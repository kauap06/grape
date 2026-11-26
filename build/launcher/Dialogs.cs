using System;
using System.Drawing;
using System.IO;
using System.Runtime.InteropServices;
using System.Windows.Forms;

namespace Grape.Launcher
{
    internal static class DialogChrome
    {
        [DllImport("dwmapi.dll")]
        private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attribute, ref int value, int size);

        public static void ApplyDarkTitleBar(Form form)
        {
            try
            {
                int enabled = 1;
                int result = DwmSetWindowAttribute(form.Handle, 20, ref enabled, sizeof(int));
                if (result != 0) DwmSetWindowAttribute(form.Handle, 19, ref enabled, sizeof(int));
            }
            catch { }
        }
    }

    internal sealed class CaPromptForm : Form
    {
        public CaPromptForm(LauncherContext context)
        {
            Text = "Confiar no certificado do Grape";
            ClientSize = new Size(640, 474);
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;
            MinimizeBox = false;
            ShowInTaskbar = false;
            TopMost = true;
            StartPosition = FormStartPosition.CenterParent;
            BackColor = Theme.Background;
            ForeColor = Theme.Text;
            Font = Theme.Ui(9.5f);
            AutoScaleMode = AutoScaleMode.Dpi;

            PictureBox shield = new PictureBox();
            shield.SetBounds(24, 20, 32, 32);
            shield.SizeMode = PictureBoxSizeMode.Zoom;
            shield.Image = SystemIcons.Shield.ToBitmap();
            Controls.Add(shield);

            Label title = Theme.Label("Confiar no certificado local do Grape?", 16f, Theme.Text, true);
            title.SetBounds(68, 18, 540, 34);
            Controls.Add(title);

            Label intro = Theme.Label("O Grape usa HTTPS neste computador. O Windows precisa confiar na autoridade certificadora (CA) local usada pelo Caddy; sem isso, a janela pode exibir um erro de certificado e o acesso à câmera, ao microfone ou ao compartilhamento de tela pode ser bloqueado.", 9.5f, Theme.Text, false);
            intro.SetBounds(24, 66, 592, 68);
            Controls.Add(intro);

            Label runLabel = Theme.Label("Clique em Instalar certificado para executar:", 9f, Theme.Muted, false);
            runLabel.SetBounds(24, 142, 592, 20);
            Controls.Add(runLabel);

            TextBox path = new TextBox();
            path.SetBounds(24, 164, 592, 28);
            path.ReadOnly = true;
            path.TabStop = false;
            path.BorderStyle = BorderStyle.FixedSingle;
            path.BackColor = Theme.Panel;
            path.ForeColor = Theme.Text;
            path.Font = Theme.Mono(8.5f);
            path.Text = context.InstallerDisplayPath();
            Controls.Add(path);

            Label note = Theme.Label("O instalador adiciona esta CA ao repositório de Autoridades Raiz Confiáveis do seu usuário. Não são necessários direitos de administrador e a confiança do Windows como um todo não é alterada.", 9f, Theme.Muted, false);
            note.SetBounds(24, 200, 592, 42);
            Controls.Add(note);

            Panel details = new Panel();
            details.SetBounds(24, 250, 592, 56);
            details.BackColor = Theme.Panel;
            Theme.DrawCardBorder(details);
            Controls.Add(details);

            Label cert = Theme.Label("Certificado: " + context.CertificateSummary(), 8.5f, Theme.Muted, false);
            cert.SetBounds(14, 10, 562, 36);
            details.Controls.Add(cert);

            Label warning = Theme.Label("Se você pular esta etapa, o Grape pode não carregar e as salas podem não acessar sua câmera ou microfone. Você pode instalar depois em Instalar certificado, na barra de título.", 9f, Theme.Warning, false);
            warning.SetBounds(24, 316, 592, 54);
            Controls.Add(warning);

            Button install = Theme.Primary("Instalar certificado");
            install.SetBounds(340, 402, 166, 40);
            install.DialogResult = DialogResult.Yes;
            install.Enabled = File.Exists(context.PromptInstallerPath());
            Controls.Add(install);

            Button skip = Theme.Secondary("Agora não", Theme.Border);
            skip.SetBounds(514, 402, 102, 40);
            skip.DialogResult = DialogResult.No;
            Controls.Add(skip);

            AcceptButton = install;
            CancelButton = skip;
        }

        protected override void OnHandleCreated(EventArgs e)
        {
            base.OnHandleCreated(e);
            DialogChrome.ApplyDarkTitleBar(this);
        }
    }

    internal sealed class BusyForm : Form
    {
        private readonly LoadingBar _bar;

        public BusyForm(string title, string detail)
        {
            Text = "Grape";
            ClientSize = new Size(470, 154);
            FormBorderStyle = FormBorderStyle.FixedDialog;
            ControlBox = false;
            ShowInTaskbar = false;
            TopMost = true;
            StartPosition = FormStartPosition.CenterParent;
            BackColor = Theme.Background;
            ForeColor = Theme.Text;
            Font = Theme.Ui(9.5f);
            AutoScaleMode = AutoScaleMode.Dpi;

            Label heading = Theme.Label(title, 12f, Theme.Text, true);
            heading.SetBounds(24, 22, 420, 28);
            Controls.Add(heading);

            Label text = Theme.Label(detail, 9f, Theme.Muted, false);
            text.SetBounds(24, 56, 420, 42);
            Controls.Add(text);

            _bar = new LoadingBar();
            _bar.SetBounds(24, 108, 420, 3);
            Controls.Add(_bar);
        }

        protected override void OnShown(EventArgs e)
        {
            base.OnShown(e);
            _bar.Start();
        }

        protected override void OnHandleCreated(EventArgs e)
        {
            base.OnHandleCreated(e);
            DialogChrome.ApplyDarkTitleBar(this);
        }
    }
}