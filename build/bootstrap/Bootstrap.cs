using System;
using System.Diagnostics;
using System.Drawing;
using System.IO;
using System.IO.Compression;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Threading;
using System.Windows.Forms;

namespace Grape.Bootstrap
{
    internal static class Program
    {
        private const int FooterSize = 32;
        private const string Magic = "GRAPESF1";

        [STAThread]
        private static int Main(string[] args)
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            try
            {
                return Run(args);
            }
            catch (Exception ex)
            {
                string log = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Grape", "bootstrap.log");
                try
                {
                    Directory.CreateDirectory(Path.GetDirectoryName(log));
                    File.AppendAllText(log, DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " " + ex + Environment.NewLine, Encoding.UTF8);
                }
                catch { }
                MessageBox.Show("Não foi possível preparar o Grape." + Environment.NewLine + Environment.NewLine + ex.Message + Environment.NewLine + Environment.NewLine + "Detalhes: " + log,
                    "Grape", MessageBoxButtons.OK, MessageBoxIcon.Error);
                return 1;
            }
        }

        private static int Run(string[] args)
        {
            string exe = Path.GetFullPath(Process.GetCurrentProcess().MainModule.FileName);
            Footer footer = ReadFooter(exe);

            string root = Environment.GetEnvironmentVariable("GRAPE_SFX_ROOT");
            if (String.IsNullOrWhiteSpace(root))
                root = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "Grape", "app");
            root = Path.GetFullPath(root).TrimEnd(Path.DirectorySeparatorChar);
            Directory.CreateDirectory(root);

            string inner = Path.Combine(root, "Grape.exe");
            string marker = Path.Combine(root, ".payload-version");
            bool needsExtract = !File.Exists(inner) || ReadMarker(marker) != footer.Version;
            bool created;
            string mutexName = "GrapeLauncher_" + ShortHash(root);

            using (Mutex mutex = new Mutex(true, mutexName, out created))
            {
                if (!created)
                {
                    MessageBox.Show("O Grape já está em execução." + Environment.NewLine + Environment.NewLine + "Use a janela do Grape na barra de tarefas para abri-lo.",
                        "Grape", MessageBoxButtons.OK, MessageBoxIcon.Information);
                    return 0;
                }

                if (needsExtract)
                {
                    ExtractWithProgress(exe, footer, root);
                    File.WriteAllText(marker, footer.Version, Encoding.ASCII);
                }
            }

            if (!File.Exists(inner))
                throw new FileNotFoundException("O executável interno do Grape não foi encontrado.", inner);

            ProcessStartInfo psi = new ProcessStartInfo();
            psi.FileName = inner;
            psi.WorkingDirectory = root;
            psi.Arguments = JoinArguments(args);
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            Process.Start(psi);
            return 0;
        }

        private static void ExtractWithProgress(string exe, Footer footer, string root)
        {
            using (SplashForm splash = new SplashForm())
            {
                splash.Show();
                Application.DoEvents();
                try
                {
                    using (FileStream file = new FileStream(exe, FileMode.Open, FileAccess.Read, FileShare.ReadWrite))
                    using (SubStream sub = new SubStream(file, footer.Offset, footer.Length))
                    using (ZipArchive zip = new ZipArchive(sub, ZipArchiveMode.Read))
                    {
                        int total = zip.Entries.Count;
                        int done = 0;
                        splash.SetProgress(0, total, "Preparando os arquivos...");
                        foreach (ZipArchiveEntry entry in zip.Entries)
                        {
                            string name = entry.FullName.Replace('\\', '/');
                            if (String.IsNullOrEmpty(name)) { done++; continue; }
                            bool isDir = name.EndsWith("/", StringComparison.Ordinal);
                            string relative = name.TrimEnd('/').Replace('/', Path.DirectorySeparatorChar);
                            if (relative.Length == 0) { done++; continue; }
                            string dest = Path.GetFullPath(Path.Combine(root, relative));
                            string rootPrefix = root + Path.DirectorySeparatorChar;
                            if (dest != root && !dest.StartsWith(rootPrefix, StringComparison.OrdinalIgnoreCase))
                                throw new InvalidDataException("Arquivo inválido no pacote: " + name);

                            if (isDir)
                            {
                                Directory.CreateDirectory(dest);
                                done++;
                                continue;
                            }

                            if (IsPreserved(relative))
                            {
                                done++;
                                continue;
                            }

                            string dir = Path.GetDirectoryName(dest);
                            if (!String.IsNullOrEmpty(dir)) Directory.CreateDirectory(dir);
                            using (Stream input = entry.Open())
                            using (FileStream output = new FileStream(dest, FileMode.Create, FileAccess.Write, FileShare.None))
                            {
                                CopyStream(input, output);
                            }

                            done++;
                            if (done % 15 == 0 || done == total)
                            {
                                splash.SetProgress(done, total, Path.GetFileName(dest));
                                Application.DoEvents();
                            }
                        }
                    }

                    Directory.CreateDirectory(Path.Combine(root, "data", "sessions"));
                    Directory.CreateDirectory(Path.Combine(root, "logs"));
                    Directory.CreateDirectory(Path.Combine(root, "deploy", "ca", "dist"));
                    Directory.CreateDirectory(Path.Combine(root, "portable", "mariadb", "data"));
                }
                finally
                {
                    splash.Close();
                }
            }
        }

        private static bool IsPreserved(string relative)
        {
            string value = relative.Replace('\\', '/').ToLowerInvariant();
            if (value == "config/local.php") return true;
            return value.StartsWith("data/", StringComparison.Ordinal)
                || value.StartsWith("logs/", StringComparison.Ordinal)
                || value.StartsWith("portable/mariadb/data/", StringComparison.Ordinal)
                || value.StartsWith("deploy/ca/dist/", StringComparison.Ordinal);
        }

        private static void CopyStream(Stream input, Stream output)
        {
            byte[] buffer = new byte[131072];
            int read;
            while ((read = input.Read(buffer, 0, buffer.Length)) > 0) output.Write(buffer, 0, read);
        }

        private static string JoinArguments(string[] args)
        {
            if (args == null || args.Length == 0) return String.Empty;
            StringBuilder builder = new StringBuilder();
            for (int i = 0; i < args.Length; i++)
            {
                if (i > 0) builder.Append(' ');
                builder.Append(Quote(args[i]));
            }
            return builder.ToString();
        }

        private static string Quote(string value)
        {
            if (String.IsNullOrEmpty(value)) return "\"\"";
            if (value.IndexOfAny(new char[] { ' ', '\t', '\n', '\v', '\"' }) < 0) return value;
            return "\"" + value.Replace("\\", "\\\\").Replace("\"", "\\\"") + "\"";
        }

        private static string ReadMarker(string path)
        {
            try { return File.Exists(path) ? File.ReadAllText(path).Trim() : String.Empty; }
            catch { return String.Empty; }
        }

        private static Footer ReadFooter(string exe)
        {
            using (FileStream file = new FileStream(exe, FileMode.Open, FileAccess.Read, FileShare.ReadWrite))
            {
                if (file.Length < FooterSize) throw new InvalidDataException("Pacote do Grape incompleto.");
                file.Seek(-FooterSize, SeekOrigin.End);
                byte[] footer = new byte[FooterSize];
                ReadExact(file, footer);
                string magic = Encoding.ASCII.GetString(footer, 24, 8);
                if (magic != Magic) throw new InvalidDataException("Pacote do Grape não reconhecido.");
                string version = Encoding.ASCII.GetString(footer, 0, 16).TrimEnd('\0', ' ');
                ulong length = BitConverter.ToUInt64(footer, 16);
                long offset = file.Length - FooterSize - (long)length;
                if (length == 0 || offset < 0) throw new InvalidDataException("Pacote do Grape inválido.");
                if (String.IsNullOrEmpty(version)) version = "dev";
                return new Footer(offset, (long)length, version);
            }
        }

        private static void ReadExact(Stream stream, byte[] buffer)
        {
            int offset = 0;
            while (offset < buffer.Length)
            {
                int read = stream.Read(buffer, offset, buffer.Length - offset);
                if (read <= 0) throw new EndOfStreamException();
                offset += read;
            }
        }

        private static string ShortHash(string text)
        {
            using (SHA1 sha = SHA1.Create())
            {
                byte[] hash = sha.ComputeHash(Encoding.UTF8.GetBytes(text ?? String.Empty));
                StringBuilder builder = new StringBuilder();
                for (int i = 0; i < 8; i++) builder.Append(hash[i].ToString("x2"));
                return builder.ToString();
            }
        }

        private struct Footer
        {
            public readonly long Offset;
            public readonly long Length;
            public readonly string Version;

            public Footer(long offset, long length, string version)
            {
                Offset = offset;
                Length = length;
                Version = version;
            }
        }

        private sealed class SubStream : Stream
        {
            private readonly Stream _base;
            private readonly long _start;
            private readonly long _length;
            private long _position;

            public SubStream(Stream baseStream, long start, long length)
            {
                _base = baseStream;
                _start = start;
                _length = length;
                _position = 0;
                _base.Position = _start;
            }

            public override bool CanRead { get { return true; } }
            public override bool CanSeek { get { return true; } }
            public override bool CanWrite { get { return false; } }
            public override long Length { get { return _length; } }
            public override long Position
            {
                get { return _position; }
                set { _position = value; _base.Position = _start + value; }
            }

            public override int Read(byte[] buffer, int offset, int count)
            {
                long remaining = _length - _position;
                if (remaining <= 0) return 0;
                if (count > remaining) count = (int)remaining;
                int read = _base.Read(buffer, offset, count);
                if (read > 0) _position += read;
                return read;
            }

            public override long Seek(long offset, SeekOrigin origin)
            {
                long target;
                if (origin == SeekOrigin.Begin) target = offset;
                else if (origin == SeekOrigin.Current) target = _position + offset;
                else target = _length + offset;
                if (target < 0 || target > _length) throw new IOException("Posição inválida no pacote.");
                _position = target;
                _base.Position = _start + target;
                return _position;
            }

            public override void Flush() { }
            public override void SetLength(long value) { throw new NotSupportedException(); }
            public override void Write(byte[] buffer, int offset, int count) { throw new NotSupportedException(); }
        }

        private sealed class SplashForm : Form
        {
            private readonly Label _detail;
            private readonly Label _percent;
            private readonly Panel _track;
            private readonly Panel _fill;

            public SplashForm()
            {
                Text = "Grape";
                ClientSize = new Size(620, 208);
                FormBorderStyle = FormBorderStyle.FixedDialog;
                MaximizeBox = false;
                MinimizeBox = false;
                StartPosition = FormStartPosition.CenterScreen;
                BackColor = Color.FromArgb(17, 17, 24);
                ForeColor = Color.FromArgb(244, 244, 248);
                Font = new Font("Segoe UI", 9.5f);
                TopMost = true;

                Label title = new Label();
                title.Text = "Preparando o Grape";
                title.Font = new Font("Segoe UI Semibold", 18f);
                title.ForeColor = ForeColor;
                title.SetBounds(30, 26, 560, 34);
                Controls.Add(title);

                Label hint = new Label();
                hint.Text = "Esta etapa acontece só na primeira abertura.";
                hint.ForeColor = Color.FromArgb(168, 168, 184);
                hint.SetBounds(32, 66, 556, 22);
                Controls.Add(hint);

                _track = new Panel();
                _track.SetBounds(32, 110, 556, 8);
                _track.BackColor = Color.FromArgb(50, 48, 66);
                Controls.Add(_track);

                _fill = new Panel();
                _fill.SetBounds(0, 0, 0, 8);
                _fill.BackColor = Color.FromArgb(139, 92, 246);
                _track.Controls.Add(_fill);

                _detail = new Label();
                _detail.Text = "Preparando os arquivos...";
                _detail.ForeColor = Color.FromArgb(190, 190, 204);
                _detail.AutoEllipsis = true;
                _detail.SetBounds(32, 132, 480, 22);
                Controls.Add(_detail);

                _percent = new Label();
                _percent.Text = "0%";
                _percent.TextAlign = ContentAlignment.MiddleRight;
                _percent.ForeColor = Color.FromArgb(190, 190, 204);
                _percent.SetBounds(520, 132, 68, 22);
                Controls.Add(_percent);

                Label note = new Label();
                note.Text = "O Grape precisa extrair os componentes para iniciar sem instalações.";
                note.ForeColor = Color.FromArgb(120, 120, 138);
                note.SetBounds(32, 166, 556, 22);
                Controls.Add(note);
            }

            public void SetProgress(int done, int total, string name)
            {
                int percent = total > 0 ? (int)Math.Round(done * 100.0 / total) : 0;
                if (percent < 0) percent = 0;
                if (percent > 100) percent = 100;
                _percent.Text = percent + "%";
                _fill.Width = (int)Math.Round(_track.ClientSize.Width * percent / 100.0);
                if (!String.IsNullOrEmpty(name)) _detail.Text = name;
                _fill.Refresh();
            }

            protected override void OnShown(EventArgs e)
            {
                base.OnShown(e);
                _fill.Width = 0;
                _track.Refresh();
            }
        }
    }
}