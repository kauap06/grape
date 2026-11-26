using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: AssemblyTitle("Grape")]
[assembly: AssemblyDescription("Iniciador da hospedagem local do Grape")]
[assembly: AssemblyProduct("Grape")]
[assembly: AssemblyCompany("Grape")]
[assembly: AssemblyCopyright("Grape")]
[assembly: AssemblyVersion("1.0.1.0")]
[assembly: AssemblyFileVersion("1.0.1.0")]
[assembly: AssemblyInformationalVersion("1.0.1")]

namespace Grape.Launcher
{
    internal static class AppInfo
    {
        public const string Version = "1.0.1";
        public const string Title = "Grape";
        public const string LocalUrl = "https://localhost:8443/";
    }

    internal sealed class LauncherOptions
    {
        public bool SmokeTest;
        public bool NoPrompt;

        public static LauncherOptions Parse(string[] args)
        {
            LauncherOptions options = new LauncherOptions();
            if (args == null) return options;
            foreach (string raw in args)
            {
                string arg = (raw ?? string.Empty).Trim().ToLowerInvariant();
                if (arg == "--smoke-test") options.SmokeTest = true;
                else if (arg == "--no-prompt") options.NoPrompt = true;
            }
            return options;
        }
    }

    internal static class Log
    {
        private static readonly object Sync = new object();
        private static string _path;

        public static void Initialize(string path)
        {
            lock (Sync)
            {
                _path = path;
                try
                {
                    string dir = Path.GetDirectoryName(path);
                    if (!string.IsNullOrEmpty(dir)) Directory.CreateDirectory(dir);
                }
                catch { }
            }
        }

        public static void Write(string message)
        {
            lock (Sync)
            {
                try
                {
                    if (string.IsNullOrEmpty(_path)) return;
                    FileInfo info = new FileInfo(_path);
                    if (info.Exists && info.Length > 4 * 1024 * 1024)
                    {
                        string old = _path + ".old";
                        try { if (File.Exists(old)) File.Delete(old); } catch { }
                        try { File.Move(_path, old); } catch { }
                    }
                    string line = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss.fff", CultureInfo.InvariantCulture)
                        + "  " + (message ?? string.Empty) + Environment.NewLine;
                    File.AppendAllText(_path, line, new UTF8Encoding(false));
                }
                catch { }
            }
        }

        public static void Exception(string context, Exception ex)
        {
            if (ex == null) { Write(context); return; }
            Write(context + ": " + ex.GetType().Name + ": " + ex.Message);
            Write(ex.StackTrace ?? string.Empty);
        }
    }

    internal sealed class LauncherState
    {
        public bool FirstRunCompleted;
        public bool CertificateInstalled;
        public string CertificateThumbprint;
        public string LastInstallResult;
    }

    internal static class StateStore
    {
        private const string KeyPath = @"Software\Grape\Launcher";

        public static LauncherState Load()
        {
            LauncherState state = new LauncherState();
            try
            {
                using (RegistryKey key = Registry.CurrentUser.OpenSubKey(KeyPath, false))
                {
                    if (key == null) return state;
                    state.FirstRunCompleted = ReadInt(key, "FirstRunCompleted") == 1;
                    state.CertificateInstalled = ReadInt(key, "CertificateInstalled") == 1;
                    state.CertificateThumbprint = Convert.ToString(key.GetValue("CertificateThumbprint", string.Empty));
                    state.LastInstallResult = Convert.ToString(key.GetValue("LastInstallResult", string.Empty));
                }
            }
            catch (Exception ex)
            {
                Log.Exception("Could not read launcher state", ex);
            }
            return state;
        }

        public static void Save(LauncherState state)
        {
            if (state == null) return;
            try
            {
                using (RegistryKey key = Registry.CurrentUser.CreateSubKey(KeyPath))
                {
                    if (key == null) return;
                    key.SetValue("FirstRunCompleted", state.FirstRunCompleted ? 1 : 0, RegistryValueKind.DWord);
                    key.SetValue("CertificateInstalled", state.CertificateInstalled ? 1 : 0, RegistryValueKind.DWord);
                    if (!string.IsNullOrEmpty(state.CertificateThumbprint))
                        key.SetValue("CertificateThumbprint", state.CertificateThumbprint, RegistryValueKind.String);
                    if (!string.IsNullOrEmpty(state.LastInstallResult))
                        key.SetValue("LastInstallResult", state.LastInstallResult, RegistryValueKind.String);
                    key.SetValue("LastLaunchUtc", DateTime.UtcNow.ToString("o", CultureInfo.InvariantCulture), RegistryValueKind.String);
                    key.SetValue("Version", AppInfo.Version, RegistryValueKind.String);
                }
            }
            catch (Exception ex)
            {
                Log.Exception("Could not write launcher state", ex);
            }
        }

        private static int ReadInt(RegistryKey key, string name)
        {
            object value = key.GetValue(name);
            if (value == null) return 0;
            int parsed;
            return int.TryParse(Convert.ToString(value, CultureInfo.InvariantCulture), out parsed) ? parsed : 0;
        }
    }

    internal sealed class CertificateInfo
    {
        public string Thumbprint;
        public string Subject;
        public DateTime NotAfter;
    }

    internal static class CertTool
    {
        public static CertificateInfo Load(string path)
        {
            try
            {
                byte[] der = ReadDer(path);
                if (der == null) return null;
                using (X509Certificate2 cert = new X509Certificate2(der))
                {
                    CertificateInfo info = new CertificateInfo();
                    info.Thumbprint = Normalize(cert.Thumbprint);
                    info.Subject = cert.Subject;
                    info.NotAfter = cert.NotAfter;
                    return info;
                }
            }
            catch (Exception ex)
            {
                Log.Exception("Could not read certificate " + path, ex);
                return null;
            }
        }

        public static string GetThumbprint(string path)
        {
            CertificateInfo info = Load(path);
            return info == null ? null : info.Thumbprint;
        }

        public static bool IsTrusted(string thumbprint)
        {
            if (string.IsNullOrEmpty(thumbprint)) return false;
            thumbprint = Normalize(thumbprint);
            if (InStore(thumbprint, StoreLocation.CurrentUser)) return true;
            if (InStore(thumbprint, StoreLocation.LocalMachine)) return true;
            return false;
        }

        public static bool InstallerMatchesCa(string installerPath, string caPath)
        {
            try
            {
                if (!File.Exists(installerPath) || !File.Exists(caPath)) return false;
                string caThumb = GetThumbprint(caPath);
                if (string.IsNullOrEmpty(caThumb)) return false;
                string text = File.ReadAllText(installerPath);
                string marker = "set \"GRAPE_CA_B64=";
                int start = text.IndexOf(marker, StringComparison.OrdinalIgnoreCase);
                if (start < 0) return false;
                start += marker.Length;
                int end = text.IndexOf((char)34, start);
                if (end < 0) return false;
                string b64 = text.Substring(start, end - start).Trim();
                if (b64.Length == 0 || b64.IndexOf("@CERT_BASE64@", StringComparison.Ordinal) >= 0) return false;
                byte[] der = Convert.FromBase64String(Regex.Replace(b64, "\\s+", string.Empty));
                using (X509Certificate2 cert = new X509Certificate2(der))
                {
                    return string.Equals(Normalize(cert.Thumbprint), caThumb, StringComparison.OrdinalIgnoreCase);
                }
            }
            catch (Exception ex)
            {
                Log.Exception("Could not inspect installer " + installerPath, ex);
                return false;
            }
        }

        public static string Normalize(string thumbprint)
        {
            if (thumbprint == null) return string.Empty;
            return thumbprint.Replace(" ", string.Empty).Trim().ToUpperInvariant();
        }

        private static bool InStore(string thumbprint, StoreLocation location)
        {
            X509Store store = null;
            try
            {
                store = new X509Store(StoreName.Root, location);
                store.Open(OpenFlags.ReadOnly | OpenFlags.OpenExistingOnly);
                foreach (X509Certificate2 cert in store.Certificates)
                {
                    if (string.Equals(Normalize(cert.Thumbprint), thumbprint, StringComparison.OrdinalIgnoreCase)) return true;
                }
            }
            catch (Exception ex)
            {
                Log.Exception("Could not read " + location + " Root store", ex);
            }
            finally
            {
                if (store != null)
                {
                    try { store.Close(); } catch { }
                }
            }
            return false;
        }

        private static byte[] ReadDer(string path)
        {
            string text = File.ReadAllText(path);
            const string begin = "-----BEGIN CERTIFICATE-----";
            const string end = "-----END CERTIFICATE-----";
            int start = text.IndexOf(begin, StringComparison.Ordinal);
            int finish = text.IndexOf(end, StringComparison.Ordinal);
            if (start >= 0 && finish > start)
            {
                string b64 = text.Substring(start + begin.Length, finish - (start + begin.Length));
                b64 = Regex.Replace(b64, "\\s+", string.Empty);
                return Convert.FromBase64String(b64);
            }
            byte[] raw = File.ReadAllBytes(path);
            if (raw.Length > 2 && raw[0] == 0x30) return raw;
            return null;
        }
    }

    internal sealed class RunResult
    {
        public int ExitCode;
        public bool TimedOut;
        public string Output;
    }

    internal static class HiddenRunner
    {
        public static RunResult Run(string scriptPath, string arguments, string workingDir, TimeSpan timeout,
            IDictionary<string, string> environment, string tag, bool closeStandardInput)
        {
            RunResult result = new RunResult();
            result.ExitCode = -1;
            result.Output = string.Empty;
            bool isBatch = scriptPath.EndsWith(".bat", StringComparison.OrdinalIgnoreCase)
                || scriptPath.EndsWith(".cmd", StringComparison.OrdinalIgnoreCase);

            string fileName;
            string commandArguments;
            if (isBatch)
            {
                string relative = MakeRelative(workingDir, scriptPath);
                fileName = Path.Combine(Environment.SystemDirectory, "cmd.exe");
                commandArguments = "/d /s /c \"\"" + relative + "\"";
                if (!string.IsNullOrEmpty(arguments)) commandArguments += " " + arguments;
                commandArguments += "\"";
            }
            else
            {
                fileName = scriptPath;
                commandArguments = arguments ?? string.Empty;
            }

            StringBuilder output = new StringBuilder();
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo(fileName, commandArguments);
                psi.WorkingDirectory = workingDir;
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.WindowStyle = ProcessWindowStyle.Hidden;
                psi.RedirectStandardOutput = true;
                psi.RedirectStandardError = true;
                psi.RedirectStandardInput = closeStandardInput;
                if (environment != null)
                {
                    foreach (KeyValuePair<string, string> pair in environment)
                        psi.EnvironmentVariables[pair.Key] = pair.Value;
                }

                using (Process process = new Process())
                {
                    process.StartInfo = psi;
                    process.OutputDataReceived += delegate(object sender, DataReceivedEventArgs e)
                    {
                        if (e.Data == null) return;
                        lock (output) output.AppendLine(e.Data);
                        Log.Write("[" + tag + "] " + e.Data);
                    };
                    process.ErrorDataReceived += delegate(object sender, DataReceivedEventArgs e)
                    {
                        if (e.Data == null) return;
                        lock (output) output.AppendLine(e.Data);
                        Log.Write("[" + tag + ":err] " + e.Data);
                    };

                    process.Start();
                    if (closeStandardInput)
                    {
                        try { process.StandardInput.Close(); } catch { }
                    }
                    process.BeginOutputReadLine();
                    process.BeginErrorReadLine();

                    int milliseconds = (int)Math.Min(int.MaxValue, Math.Max(1000, timeout.TotalMilliseconds));
                    bool exited = process.WaitForExit(milliseconds);
                    if (!exited)
                    {
                        result.TimedOut = true;
                        Log.Write("[" + tag + "] timed out after " + milliseconds + " ms; terminating process tree.");
                        KillTree(process.Id);
                        try { process.WaitForExit(3000); } catch { }
                    }
                    else
                    {
                        try { process.WaitForExit(1500); } catch { }
                        result.ExitCode = process.ExitCode;
                    }
                }
            }
            catch (Exception ex)
            {
                Log.Exception("Hidden process failed: " + scriptPath, ex);
            }

            lock (output) result.Output = output.ToString();
            return result;
        }

        public static void KillTree(int pid)
        {
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo("taskkill.exe", "/PID " + pid + " /T /F");
                psi.UseShellExecute = false;
                psi.CreateNoWindow = true;
                psi.WindowStyle = ProcessWindowStyle.Hidden;
                using (Process killer = Process.Start(psi))
                {
                    if (killer != null) killer.WaitForExit(5000);
                }
            }
            catch { }
            try
            {
                Process process = Process.GetProcessById(pid);
                if (process != null) process.Kill();
            }
            catch { }
        }

        private static string MakeRelative(string workingDir, string path)
        {
            string prefix = workingDir.TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (path.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
                return path.Substring(prefix.Length);
            return path;
        }
    }

    internal sealed class JobObject : IDisposable
    {
        [StructLayout(LayoutKind.Sequential)]
        private struct IO_COUNTERS
        {
            public ulong ReadOperationCount;
            public ulong WriteOperationCount;
            public ulong OtherOperationCount;
            public ulong ReadTransferCount;
            public ulong WriteTransferCount;
            public ulong OtherTransferCount;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_BASIC_LIMIT_INFORMATION
        {
            public long PerProcessUserTimeLimit;
            public long PerJobUserTimeLimit;
            public uint LimitFlags;
            public UIntPtr MinimumWorkingSetSize;
            public UIntPtr MaximumWorkingSetSize;
            public uint ActiveProcessLimit;
            public UIntPtr Affinity;
            public uint PriorityClass;
            public uint SchedulingClass;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct JOBOBJECT_EXTENDED_LIMIT_INFORMATION
        {
            public JOBOBJECT_BASIC_LIMIT_INFORMATION BasicLimitInformation;
            public IO_COUNTERS IoInfo;
            public UIntPtr ProcessMemoryLimit;
            public UIntPtr JobMemoryLimit;
            public UIntPtr PeakProcessMemoryUsed;
            public UIntPtr PeakJobMemoryUsed;
        }

        private const int JobObjectExtendedLimitInformation = 9;
        private const uint JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x2000;

        [DllImport("kernel32.dll", CharSet = CharSet.Unicode)]
        private static extern IntPtr CreateJobObject(IntPtr attributes, string name);

        [DllImport("kernel32.dll")]
        private static extern bool SetInformationJobObject(IntPtr job, int infoClass, IntPtr info, uint length);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);

        [DllImport("kernel32.dll", SetLastError = true)]
        private static extern bool CloseHandle(IntPtr handle);

        private IntPtr _handle;

        public JobObject()
        {
            _handle = CreateJobObject(IntPtr.Zero, null);
            if (_handle == IntPtr.Zero)
            {
                Log.Write("CreateJobObject failed: " + Marshal.GetLastWin32Error());
                return;
            }
            IntPtr infoPointer = IntPtr.Zero;
            try
            {
                JOBOBJECT_EXTENDED_LIMIT_INFORMATION info = new JOBOBJECT_EXTENDED_LIMIT_INFORMATION();
                info.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
                int size = Marshal.SizeOf(typeof(JOBOBJECT_EXTENDED_LIMIT_INFORMATION));
                infoPointer = Marshal.AllocHGlobal(size);
                Marshal.StructureToPtr(info, infoPointer, false);
                if (!SetInformationJobObject(_handle, JobObjectExtendedLimitInformation, infoPointer, (uint)size))
                    Log.Write("SetInformationJobObject failed: " + Marshal.GetLastWin32Error());
            }
            catch (Exception ex)
            {
                Log.Exception("Could not configure job object", ex);
            }
            finally
            {
                if (infoPointer != IntPtr.Zero) Marshal.FreeHGlobal(infoPointer);
            }
        }

        public void Assign(Process process)
        {
            if (_handle == IntPtr.Zero || process == null) return;
            try
            {
                if (!AssignProcessToJobObject(_handle, process.Handle))
                    Log.Write("AssignProcessToJobObject failed: " + Marshal.GetLastWin32Error());
            }
            catch (Exception ex)
            {
                Log.Exception("Could not assign process to job", ex);
            }
        }

        public void Dispose()
        {
            if (_handle != IntPtr.Zero)
            {
                CloseHandle(_handle);
                _handle = IntPtr.Zero;
            }
        }
    }

    internal sealed class CaInstallResult
    {
        public bool Success;
        public bool TimedOut;
        public int ExitCode;
        public string Installer;
        public string Output;

        public string Describe()
        {
            string where = Installer ?? "(instalador não encontrado)";
            string reason = TimedOut ? "tempo esgotado" : ("código de saída " + ExitCode);
            return "Instalador: " + where + Environment.NewLine + "Resultado: " + reason;
        }
    }

    internal sealed class LauncherContext : IDisposable
    {
        private readonly object _sync = new object();
        private JobObject _job;
        private Process _host;
        private bool _stopRequested;
        private bool _stopCompleted;
        private bool _disposed;

        public readonly LauncherOptions Options;
        public readonly string Root;
        public readonly string LogsDir;
        public readonly string StartHostPath;
        public readonly string StopHostPath;
        public readonly string MakeGuestInstallerPath;
        public readonly string DistInstallerPath;
        public readonly string DeployInstallerPath;
        public readonly string CaddyRootPath;

        public bool HttpsReady;
        public bool CertificateKnown;
        public bool CertificateInstalled;
        public string CertificateThumbprint;
        public string CertificateSubject;
        public DateTime CertificateNotAfter;
        public bool FirstRun;
        public string LastError;
        public string RadminIp;
        public DateTime LaunchUtc;

        public LauncherContext(string root, LauncherOptions options)
        {
            Root = root;
            Options = options;
            LogsDir = Path.Combine(root, "logs");
            StartHostPath = Path.Combine(root, "bin", "start-host.bat");
            StopHostPath = Path.Combine(root, "bin", "stop-host.bat");
            MakeGuestInstallerPath = Path.Combine(root, "deploy", "ca", "make-guest-installer.bat");
            DistInstallerPath = Path.Combine(root, "deploy", "ca", "dist", "install-ca.bat");
            DeployInstallerPath = Path.Combine(root, "deploy", "ca", "install-ca.bat");
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            CaddyRootPath = Path.Combine(appData, "Caddy", "pki", "authorities", "local", "root.crt");
        }

        public void Initialize()
        {
            Directory.CreateDirectory(LogsDir);
            Log.Initialize(Path.Combine(LogsDir, "launcher.log"));
            RadminIp = FindRadminIp();
            Log.Write("Grape launcher " + AppInfo.Version + " starting");
            Log.Write("Root: " + Root);
            Log.Write("OS: " + Environment.OSVersion + " / " + (Environment.Is64BitProcess ? "x64" : "x86"));
            Log.Write("Radmin IPv4: " + (RadminIp ?? "(not detected)"));
            if (Options.SmokeTest) Log.Write("Smoke-test mode: no prompt, no browser, no state changes.");
        }

        public string Validate()
        {
            string[] required = new string[]
            {
                StartHostPath,
                StopHostPath,
                Path.Combine(Root, "portable", "php", "php.exe"),
                Path.Combine(Root, "portable", "caddy", "caddy.exe"),
                Path.Combine(Root, "portable", "mariadb", "bin", "mysqld.exe"),
                Path.Combine(Root, "vendor", "autoload.php")
            };
            foreach (string path in required)
            {
                if (!File.Exists(path)) return "Arquivo obrigatório não encontrado: " + path;
            }
            if (!Directory.Exists(Path.Combine(Root, "public"))) return "Pasta obrigatória não encontrada: public";
            return null;
        }

        public bool StartServices()
        {
            lock (_sync)
            {
                if (_stopRequested) return false;
                if (HttpsReady) return true;
            }

            LastError = Validate();
            if (LastError != null)
            {
                Log.Write("Validation failed: " + LastError);
                return false;
            }

            LaunchUtc = DateTime.UtcNow;
            try
            {
                Directory.CreateDirectory(LogsDir);
                _job = new JobObject();
                _job.Assign(StartHostProcess());
            }
            catch (Exception ex)
            {
                Log.Exception("Could not start the host batch", ex);
                LastError = "Não foi possível iniciar bin\\start-host.bat: " + ex.Message;
                StopServices();
                return false;
            }

            Log.Write("Waiting for https://localhost:8443 ...");
            if (!WaitForHttps(TimeSpan.FromSeconds(180)))
            {
                LastError = "O serviço HTTPS local não ficou pronto. " + DiagnosticsTail();
                Log.Write(LastError);
                StopServices();
                return false;
            }

            HttpsReady = true;
            Log.Write("HTTPS is ready.");
            EnsureGuestInstaller();
            DetectCertificate();
            FirstRun = !StateStore.Load().FirstRunCompleted;
            Log.Write("Certificate installed: " + CertificateInstalled + "; thumbprint: " + (CertificateThumbprint ?? "(unknown)") + "; first run: " + FirstRun);
            return true;
        }

        private Process StartHostProcess()
        {
            ProcessStartInfo psi = new ProcessStartInfo(Path.Combine(Environment.SystemDirectory, "cmd.exe"));
            string relative = RelativeToRoot(StartHostPath);
            psi.Arguments = "/d /s /c \"\"" + relative + "\"\"";
            psi.WorkingDirectory = Root;
            psi.UseShellExecute = false;
            psi.CreateNoWindow = true;
            psi.WindowStyle = ProcessWindowStyle.Hidden;
            psi.RedirectStandardOutput = true;
            psi.RedirectStandardError = true;
            psi.EnvironmentVariables["GRAPE_HEADLESS"] = "1";
            psi.EnvironmentVariables["GRAPE_LAUNCHER"] = "1";

            Process process = new Process();
            process.StartInfo = psi;
            process.EnableRaisingEvents = true;
            process.OutputDataReceived += delegate(object sender, DataReceivedEventArgs e)
            {
                if (e.Data != null) Log.Write("[start-host] " + e.Data);
            };
            process.ErrorDataReceived += delegate(object sender, DataReceivedEventArgs e)
            {
                if (e.Data != null) Log.Write("[start-host:err] " + e.Data);
            };
            process.Exited += delegate(object sender, EventArgs e)
            {
                try { Log.Write("start-host.bat exited with code " + process.ExitCode); }
                catch { }
            };
            process.Start();
            _host = process;
            process.BeginOutputReadLine();
            process.BeginErrorReadLine();
            Log.Write("Started bin\\start-host.bat hidden, PID " + process.Id);
            return process;
        }

        private void EnsureGuestInstaller()
        {
            if (_stopRequested) return;
            if (!WaitForFile(CaddyRootPath, TimeSpan.FromSeconds(45)))
            {
                Log.Write("Caddy root certificate not found at " + CaddyRootPath);
                return;
            }

            bool fresh = WaitForFreshFile(DistInstallerPath, TimeSpan.FromSeconds(30), LaunchUtc.AddSeconds(-3));
            if (fresh && CertTool.InstallerMatchesCa(DistInstallerPath, CaddyRootPath))
            {
                Log.Write("Guest CA installer already matches the live Caddy CA.");
                return;
            }

            if (!File.Exists(MakeGuestInstallerPath))
            {
                Log.Write("make-guest-installer.bat is missing; will use deploy\\ca\\install-ca.bat if needed.");
                return;
            }

            Log.Write("Refreshing deploy\\ca\\dist\\install-ca.bat from the live Caddy CA ...");
            Dictionary<string, string> env = new Dictionary<string, string>();
            env["GRAPE_HEADLESS"] = "1";
            RunResult result = HiddenRunner.Run(MakeGuestInstallerPath, string.Empty, Root,
                TimeSpan.FromSeconds(60), env, "make-guest-installer", true);
            Log.Write("make-guest-installer exit=" + result.ExitCode + " timedOut=" + result.TimedOut);
        }

        private void DetectCertificate()
        {
            string caPath = ResolveCaPath();
            CertificateKnown = false;
            CertificateInstalled = false;
            if (caPath == null)
            {
                Log.Write("No Caddy CA found.");
                return;
            }
            CertificateInfo info = CertTool.Load(caPath);
            if (info == null)
            {
                Log.Write("Caddy CA exists but could not be parsed: " + caPath);
                return;
            }
            CertificateKnown = true;
            CertificateThumbprint = info.Thumbprint;
            CertificateSubject = info.Subject;
            CertificateNotAfter = info.NotAfter;
            CertificateInstalled = CertTool.IsTrusted(info.Thumbprint);
            Log.Write("Caddy CA: " + info.Subject + " / " + info.Thumbprint + " / expira em " + info.NotAfter.ToString("u"));
        }

        public string ResolveCaPath()
        {
            if (File.Exists(CaddyRootPath)) return CaddyRootPath;
            string dist = Path.Combine(Root, "deploy", "ca", "dist", "caddy-root.crt");
            if (File.Exists(dist)) return dist;
            return null;
        }

        public string SelectInstaller()
        {
            string ca = ResolveCaPath();
            if (ca != null && File.Exists(DistInstallerPath) && CertTool.InstallerMatchesCa(DistInstallerPath, ca))
                return DistInstallerPath;
            if (File.Exists(DeployInstallerPath))
            {
                Log.Write("Using deploy\\ca\\install-ca.bat (dist installer missing or stale).");
                return DeployInstallerPath;
            }
            if (File.Exists(DistInstallerPath))
            {
                Log.Write("Using deploy\\ca\\dist\\install-ca.bat although it could not be verified.");
                return DistInstallerPath;
            }
            return null;
        }

        public string PromptInstallerPath()
        {
            string selected = SelectInstaller();
            return selected ?? DistInstallerPath;
        }

        public CaInstallResult InstallCertificate()
        {
            CaInstallResult result = new CaInstallResult();
            string caPath = ResolveCaPath();
            string installer = SelectInstaller();
            result.Installer = installer;
            if (installer == null)
            {
                result.ExitCode = -1;
                result.Output = "Nenhum instalador da CA foi encontrado.";
                Log.Write(result.Output);
                return result;
            }

            Dictionary<string, string> env = new Dictionary<string, string>();
            env["GRAPE_HEADLESS"] = "1";
            Log.Write("Running CA installer: " + installer + " /silent");
            RunResult run = HiddenRunner.Run(installer, "/silent", Root, TimeSpan.FromSeconds(120), env, "install-ca", true);
            result.ExitCode = run.ExitCode;
            result.TimedOut = run.TimedOut;
            result.Output = run.Output;

            Thread.Sleep(700);
            if (caPath != null)
            {
                CertificateInfo info = CertTool.Load(caPath);
                if (info != null)
                {
                    CertificateThumbprint = info.Thumbprint;
                    CertificateSubject = info.Subject;
                    CertificateNotAfter = info.NotAfter;
                    CertificateKnown = true;
                    CertificateInstalled = CertTool.IsTrusted(info.Thumbprint);
                }
            }
            result.Success = CertificateInstalled;
            Log.Write("CA installer finished: exit=" + run.ExitCode + " timedOut=" + run.TimedOut + " trusted=" + CertificateInstalled);
            return result;
        }

        public void MarkFirstRunCompleted(string result)
        {
            LauncherState state = StateStore.Load();
            state.FirstRunCompleted = true;
            state.CertificateInstalled = CertificateInstalled;
            state.CertificateThumbprint = CertificateThumbprint;
            state.LastInstallResult = result;
            StateStore.Save(state);
            FirstRun = false;
            Log.Write("First-run marker saved: " + result);
        }

        public void SaveCertificateState()
        {
            LauncherState state = StateStore.Load();
            state.FirstRunCompleted = true;
            state.CertificateInstalled = CertificateInstalled;
            state.CertificateThumbprint = CertificateThumbprint;
            state.LastInstallResult = "installed";
            StateStore.Save(state);
            FirstRun = false;
        }

        public void StopServices()
        {
            lock (_sync)
            {
                if (_stopCompleted) return;
                if (_stopRequested) return;
                _stopRequested = true;
                _stopCompleted = true;
            }
            Log.Write("Stopping Grape services ...");
            ShutdownMariaDb();

            if (File.Exists(StopHostPath))
            {
                Dictionary<string, string> env = new Dictionary<string, string>();
                env["GRAPE_HEADLESS"] = "1";
                RunResult stop = HiddenRunner.Run(StopHostPath, string.Empty, Root,
                    TimeSpan.FromSeconds(30), env, "stop-host", true);
                Log.Write("stop-host exit=" + stop.ExitCode + " timedOut=" + stop.TimedOut);
            }

            if (_host != null)
            {
                try
                {
                    if (!_host.HasExited) _host.Kill();
                }
                catch { }
                try { _host.Dispose(); } catch { }
                _host = null;
            }

            if (_job != null)
            {
                try { _job.Dispose(); } catch { }
                _job = null;
            }
            HttpsReady = false;
            Log.Write("Grape services stopped.");
        }

        private void ShutdownMariaDb()
        {
            string admin = Path.Combine(Root, "portable", "mariadb", "bin", "mariadb-admin.exe");
            if (!File.Exists(admin)) return;
            try
            {
                string args = "--protocol=tcp --host=127.0.0.1 --port=33070 --user=root --connect-timeout=3 shutdown";
                RunResult result = HiddenRunner.Run(admin, args, Root, TimeSpan.FromSeconds(8), null, "mariadb-admin", true);
                Log.Write("mariadb-admin shutdown exit=" + result.ExitCode + " timedOut=" + result.TimedOut);
            }
            catch (Exception ex)
            {
                Log.Exception("Could not shut down MariaDB gracefully", ex);
            }
        }

        private bool WaitForHttps(TimeSpan timeout)
        {
            bool loggedFailure = false;
            Stopwatch watch = Stopwatch.StartNew();
            while (watch.Elapsed < timeout)
            {
                if (_stopRequested) return false;
                try
                {
                    if (_host != null && _host.HasExited && watch.Elapsed.TotalSeconds > 8)
                    {
                        Log.Write("start-host.bat exited before the HTTPS endpoint became ready.");
                        return false;
                    }
                }
                catch { }
                try
                {
                    HttpWebRequest request = (HttpWebRequest)WebRequest.Create(AppInfo.LocalUrl);
                    request.Method = "GET";
                    request.Timeout = 5000;
                    request.ReadWriteTimeout = 5000;
                    request.AllowAutoRedirect = false;
                    request.Proxy = null;
                    request.KeepAlive = false;
                    request.UserAgent = "GrapeLauncher/" + AppInfo.Version;
                    request.ServerCertificateValidationCallback = delegate { return true; };
                    using (HttpWebResponse response = (HttpWebResponse)request.GetResponse())
                    {
                        int code = (int)response.StatusCode;
                        if (code >= 200 && code < 400) return true;
                    }
                }
                catch (WebException ex)
                {
                    if (!loggedFailure)
                    {
                        Log.Write("HTTPS poll failed: " + ex.Status + " / " + ex.Message);
                        loggedFailure = true;
                    }
                    HttpWebResponse response = ex.Response as HttpWebResponse;
                    if (response != null)
                    {
                        try { response.Close(); } catch { }
                    }
                }
                catch (Exception ex)
                {
                    if (!loggedFailure)
                    {
                        Log.Write("HTTPS poll failed: " + ex.Message);
                        loggedFailure = true;
                    }
                }
                Thread.Sleep(1500);
            }
            return false;
        }

        private static bool WaitForFile(string path, TimeSpan timeout)
        {
            Stopwatch watch = Stopwatch.StartNew();
            while (watch.Elapsed < timeout)
            {
                if (File.Exists(path)) return true;
                Thread.Sleep(1000);
            }
            return File.Exists(path);
        }

        private static bool WaitForFreshFile(string path, TimeSpan timeout, DateTime newerThanUtc)
        {
            Stopwatch watch = Stopwatch.StartNew();
            while (watch.Elapsed < timeout)
            {
                try
                {
                    if (File.Exists(path) && File.GetLastWriteTimeUtc(path) >= newerThanUtc) return true;
                }
                catch { }
                Thread.Sleep(1000);
            }
            try { return File.Exists(path) && File.GetLastWriteTimeUtc(path) >= newerThanUtc; }
            catch { return false; }
        }

        public void OpenLogs()
        {
            try
            {
                ProcessStartInfo psi = new ProcessStartInfo("explorer.exe", "\"" + LogsDir + "\"");
                psi.UseShellExecute = true;
                Process.Start(psi);
            }
            catch (Exception ex)
            {
                Log.Exception("Could not open the logs folder", ex);
            }
        }

        public string InstallerDisplayPath()
        {
            string path = PromptInstallerPath();
            if (string.IsNullOrEmpty(path)) return "(não encontrado)";
            if (path.StartsWith(Root, StringComparison.OrdinalIgnoreCase))
                return path.Substring(Root.Length).TrimStart('\\');
            return path;
        }

        public string CertificateSummary()
        {
            if (!CertificateKnown) return "CA local não encontrada";
            return CertificateSubject + " (expira em " + CertificateNotAfter.ToString("yyyy-MM-dd") + ")";
        }

        public string DiagnosticsTail()
        {
            StringBuilder builder = new StringBuilder();
            builder.AppendLine("Consulte os logs: " + LogsDir);
            string[] files = new string[]
            {
                Path.Combine(LogsDir, "launcher.log"),
                Path.Combine(LogsDir, "http.log"),
                Path.Combine(LogsDir, "websocket.log"),
                Path.Combine(LogsDir, "caddy.log"),
                Path.Combine(LogsDir, "mysql.log")
            };
            foreach (string file in files)
            {
                string tail = ReadTail(file, 6, 64 * 1024);
                if (string.IsNullOrEmpty(tail)) continue;
                builder.AppendLine();
                builder.AppendLine("--- " + Path.GetFileName(file) + " ---");
                builder.AppendLine(tail);
            }
            string text = builder.ToString();
            if (text.Length > 4000) text = text.Substring(text.Length - 4000);
            return text;
        }

        private static string ReadTail(string path, int maxLines, int maxBytes)
        {
            try
            {
                if (!File.Exists(path)) return string.Empty;
                using (FileStream stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.ReadWrite))
                {
                    long start = Math.Max(0, stream.Length - maxBytes);
                    stream.Seek(start, SeekOrigin.Begin);
                    using (StreamReader reader = new StreamReader(stream, Encoding.UTF8, true))
                    {
                        string text = reader.ReadToEnd();
                        string[] lines = text.Replace("\r\n", "\n").Split((char)10);
                        int count = Math.Min(maxLines, lines.Length);
                        StringBuilder builder = new StringBuilder();
                        for (int i = lines.Length - count; i < lines.Length; i++)
                        {
                            if (i < 0) continue;
                            builder.AppendLine(lines[i]);
                        }
                        return builder.ToString().Trim();
                    }
                }
            }
            catch { return string.Empty; }
        }

        private string RelativeToRoot(string path)
        {
            string prefix = Root.TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (path.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)) return path.Substring(prefix.Length);
            return path;
        }

        private static string FindRadminIp()
        {
            try
            {
                foreach (NetworkInterface ni in NetworkInterface.GetAllNetworkInterfaces())
                {
                    if (ni.OperationalStatus != OperationalStatus.Up) continue;
                    foreach (UnicastIPAddressInformation address in ni.GetIPProperties().UnicastAddresses)
                    {
                        if (address.Address.AddressFamily != AddressFamily.InterNetwork) continue;
                        string value = address.Address.ToString();
                        if (value.StartsWith("26.", StringComparison.Ordinal)) return value;
                    }
                }
            }
            catch { }
            return null;
        }

        public int RunSmokeTest()
        {
            Log.Write("Smoke test: starting services");
            bool ready = StartServices();
            StringBuilder report = new StringBuilder();
            report.AppendLine("Grape launcher smoke test");
            report.AppendLine("Time: " + DateTime.Now.ToString("u"));
            report.AppendLine("Root: " + Root);
            report.AppendLine("HTTPS ready: " + ready);
            report.AppendLine("Certificate known: " + CertificateKnown);
            report.AppendLine("Certificate installed: " + CertificateInstalled);
            report.AppendLine("Certificate thumbprint: " + (CertificateThumbprint ?? "(none)"));
            report.AppendLine("First run marker pending: " + FirstRun);
            report.AppendLine("Installer: " + InstallerDisplayPath());
            report.AppendLine("Radmin IPv4: " + (RadminIp ?? "(none)"));
            if (!ready) report.AppendLine("Error: " + (LastError ?? "(none)"));
            StopServices();
            report.AppendLine("Services stopped: true");
            report.AppendLine("Smoke test: " + (ready ? "PASS" : "FAIL"));
            try { File.WriteAllText(Path.Combine(LogsDir, "launcher-smoke.txt"), report.ToString()); }
            catch (Exception ex) { Log.Exception("Could not write smoke report", ex); }
            Log.Write("Smoke test: " + (ready ? "PASS" : "FAIL"));
            return ready ? 0 : 1;
        }

        public void Dispose()
        {
            if (_disposed) return;
            _disposed = true;
            if (_job != null)
            {
                try { _job.Dispose(); } catch { }
                _job = null;
            }
            if (_host != null)
            {
                try { _host.Dispose(); } catch { }
                _host = null;
            }
        }
    }

    internal static class Program
    {
        [STAThread]
        private static int Main(string[] args)
        {
            LauncherOptions options = LauncherOptions.Parse(args);
            try { ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072; } catch { }
            try { ServicePointManager.Expect100Continue = false; } catch { }
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);
            string root = Path.GetFullPath(AppDomain.CurrentDomain.BaseDirectory).TrimEnd(Path.DirectorySeparatorChar);

            string mutexName = "GrapeLauncher_" + ShortHash(root);
            bool createdNew;
            using (Mutex mutex = new Mutex(true, mutexName, out createdNew))
            {
                if (!createdNew)
                {
                    if (!options.SmokeTest)
                    {
                        MessageBox.Show("O Grape já está em execução.\n\nUse a janela do Grape na barra de tarefas para abri-lo.",
                            "Grape", MessageBoxButtons.OK, MessageBoxIcon.Information);
                    }
                    return 0;
                }

                using (LauncherContext context = new LauncherContext(root, options))
                {
                    context.Initialize();
                    if (options.SmokeTest) return context.RunSmokeTest();

                    Application.ThreadException += delegate(object sender, ThreadExceptionEventArgs e)
                    {
                        Log.Exception("Unhandled UI exception", e.Exception);
                        MessageBox.Show("O Grape encontrou um erro inesperado.\n\n" + e.Exception.Message + "\n\nRegistros: " + context.LogsDir,
                            "Grape", MessageBoxButtons.OK, MessageBoxIcon.Error);
                    };
                    AppDomain.CurrentDomain.UnhandledException += delegate(object sender, UnhandledExceptionEventArgs e)
                    {
                        Log.Exception("Unhandled exception", e.ExceptionObject as Exception);
                    };

                    try
                    {
                        Application.Run(new MainForm(context));
                        return 0;
                    }
                    catch (Exception ex)
                    {
                        Log.Exception("Fatal launcher error", ex);
                        MessageBox.Show("Não foi possível iniciar o Grape.\n\n" + ex.Message + "\n\nRegistros: " + context.LogsDir,
                            "Grape", MessageBoxButtons.OK, MessageBoxIcon.Error);
                        return 1;
                    }
                }
            }
        }

        private static string ShortHash(string text)
        {
            using (SHA1 sha = SHA1.Create())
            {
                byte[] hash = sha.ComputeHash(Encoding.UTF8.GetBytes(text ?? string.Empty));
                StringBuilder builder = new StringBuilder();
                for (int i = 0; i < 8; i++) builder.Append(hash[i].ToString("x2"));
                return builder.ToString();
            }
        }
    }
}