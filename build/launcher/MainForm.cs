using System;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Text;
using System.IO;
using System.Runtime.InteropServices;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace Grape.Launcher
{
    internal static class Theme
    {
        public static readonly Color Background = Color.FromArgb(11, 7, 23);
        public static readonly Color TitleBar = Color.FromArgb(17, 11, 32);
        public static readonly Color TitleBarEnd = Color.FromArgb(12, 8, 23);
        public static readonly Color Panel = Color.FromArgb(23, 18, 43);
        public static readonly Color Surface = Color.FromArgb(29, 23, 54);
        public static readonly Color Hover = Color.FromArgb(39, 31, 71);
        public static readonly Color Border = Color.FromArgb(58, 42, 98);
        public static readonly Color BorderSoft = Color.FromArgb(40, 30, 71);
        public static readonly Color Text = Color.FromArgb(246, 241, 255);
        public static readonly Color Muted = Color.FromArgb(168, 159, 200);
        public static readonly Color Accent = Color.FromArgb(138, 63, 224);
        public static readonly Color AccentHover = Color.FromArgb(157, 82, 240);
        public static readonly Color Success = Color.FromArgb(78, 227, 160);
        public static readonly Color Warning = Color.FromArgb(229, 169, 61);
        public static readonly Color Danger = Color.FromArgb(229, 72, 77);

        private static PrivateFontCollection _fonts;
        private static string _family = "Segoe UI";
        private static string _mono = "Consolas";
        private static bool _initialized;

        public static void Initialize(string root)
        {
            if (_initialized) return;
            _initialized = true;
            try
            {
                string dir = Path.Combine(root, "public", "assets", "fonts");
                PrivateFontCollection fonts = new PrivateFontCollection();
                string[] files = new string[]
                {
                    "PlusJakartaSans-Regular.ttf",
                    "PlusJakartaSans-SemiBold.ttf"
                };
                foreach (string file in files)
                {
                    string full = Path.Combine(dir, file);
                    if (File.Exists(full)) fonts.AddFontFile(full);
                }
                if (fonts.Families.Length > 0)
                {
                    _fonts = fonts;
                    _family = fonts.Families[0].Name;
                }
            }
            catch { }
        }

        public static Font Ui(float size)
        {
            try { return new Font(_family, size, FontStyle.Regular, GraphicsUnit.Point); }
            catch { return new Font("Segoe UI", size, FontStyle.Regular, GraphicsUnit.Point); }
        }

        public static Font Bold(float size)
        {
            try { return new Font(_family, size, FontStyle.Bold, GraphicsUnit.Point); }
            catch { return new Font("Segoe UI", size, FontStyle.Bold, GraphicsUnit.Point); }
        }

        public static Font Mono(float size)
        {
            return new Font(_mono, size, FontStyle.Regular, GraphicsUnit.Point);
        }

        public static Label Label(string text, float size, Color color, bool bold)
        {
            Label label = new Label();
            label.Text = text;
            label.AutoSize = false;
            label.Font = bold ? Bold(size) : Ui(size);
            label.ForeColor = color;
            label.BackColor = Color.Transparent;
            label.UseMnemonic = false;
            return label;
        }

        public static Button Primary(string text)
        {
            RoundedButton button = new RoundedButton();
            button.Text = text;
            button.IsPrimary = true;
            button.Height = 40;
            button.Font = Bold(9.5f);
            return button;
        }

        public static Button Secondary(string text, Color? borderColor)
        {
            RoundedButton button = new RoundedButton();
            button.Text = text;
            button.IsPrimary = false;
            button.Height = 40;
            button.Font = Bold(9.5f);
            return button;
        }

        public static GraphicsPath RoundedPath(Rectangle bounds, int radius)
        {
            GraphicsPath path = new GraphicsPath();
            int diameter = radius * 2;
            if (diameter <= 0)
            {
                path.AddRectangle(bounds);
                path.CloseFigure();
                return path;
            }
            Rectangle arc = new Rectangle(bounds.X, bounds.Y, diameter, diameter);
            path.AddArc(arc, 180, 90);
            arc.X = bounds.Right - diameter;
            path.AddArc(arc, 270, 90);
            arc.Y = bounds.Bottom - diameter;
            path.AddArc(arc, 0, 90);
            arc.X = bounds.Left;
            path.AddArc(arc, 90, 90);
            path.CloseFigure();
            return path;
        }

        public static void DrawCardBorder(Panel panel)
        {
            panel.Paint += delegate(object sender, PaintEventArgs e)
            {
                using (Pen pen = new Pen(Border))
                {
                    e.Graphics.DrawRectangle(pen, 0, 0, panel.Width - 1, panel.Height - 1);
                }
            };
        }
    }

    internal sealed class RoundedButton : Button
    {
        public bool IsPrimary;
        public int CornerRadius = 10;
        private bool _hover;

        public RoundedButton()
        {
            SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
            FlatStyle = FlatStyle.Flat;
            FlatAppearance.BorderSize = 0;
            UseVisualStyleBackColor = false;
            BackColor = Color.Transparent;
            ForeColor = Theme.Text;
            Cursor = Cursors.Hand;
            TabStop = true;
        }

        protected override void OnMouseEnter(EventArgs e)
        {
            _hover = true;
            Invalidate();
            base.OnMouseEnter(e);
        }

        protected override void OnMouseLeave(EventArgs e)
        {
            _hover = false;
            Invalidate();
            base.OnMouseLeave(e);
        }

        protected override void OnEnabledChanged(EventArgs e)
        {
            Invalidate();
            base.OnEnabledChanged(e);
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            Color fill;
            Color border;
            Color foreground;
            if (!Enabled)
            {
                fill = Theme.Panel;
                border = Theme.BorderSoft;
                foreground = Color.FromArgb(115, Theme.Muted);
            }
            else if (IsPrimary)
            {
                fill = _hover ? Theme.AccentHover : Theme.Accent;
                border = fill;
                foreground = Color.White;
            }
            else
            {
                fill = _hover ? Theme.Hover : Theme.Panel;
                border = Theme.Border;
                foreground = Theme.Text;
            }
            Rectangle bounds = new Rectangle(0, 0, Width - 1, Height - 1);
            using (GraphicsPath path = Theme.RoundedPath(bounds, CornerRadius))
            {
                using (SolidBrush brush = new SolidBrush(fill)) e.Graphics.FillPath(brush, path);
                using (Pen pen = new Pen(border)) e.Graphics.DrawPath(pen, path);
            }
            TextRenderer.DrawText(e.Graphics, Text, Font, new Rectangle(4, 0, Width - 8, Height), foreground,
                TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.EndEllipsis | TextFormatFlags.NoPrefix);
            if (Focused && ShowFocusCues)
            {
                ControlPaint.DrawFocusRectangle(e.Graphics, new Rectangle(6, 6, Width - 12, Height - 12), foreground, fill);
            }
        }
    }

    internal enum WindowGlyph
    {
        Minimize,
        Maximize,
        Restore,
        Close
    }

    internal sealed class WindowButton : Button
    {
        public WindowGlyph Glyph;
        private bool _hover;

        public WindowButton(WindowGlyph glyph)
        {
            Glyph = glyph;
            SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer, true);
            FlatStyle = FlatStyle.Flat;
            FlatAppearance.BorderSize = 0;
            UseVisualStyleBackColor = false;
            BackColor = Theme.TitleBarEnd;
            Cursor = Cursors.Hand;
            TabStop = false;
            Text = string.Empty;
        }

        protected override void OnMouseEnter(EventArgs e)
        {
            _hover = true;
            Invalidate();
            base.OnMouseEnter(e);
        }

        protected override void OnMouseLeave(EventArgs e)
        {
            _hover = false;
            Invalidate();
            base.OnMouseLeave(e);
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            bool closing = Glyph == WindowGlyph.Close;
            Color background = _hover ? (closing ? Theme.Danger : Theme.Hover) : Theme.TitleBarEnd;
            Color foreground = _hover && closing ? Color.White : Theme.Muted;
            using (SolidBrush brush = new SolidBrush(background)) e.Graphics.FillRectangle(brush, ClientRectangle);
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            using (Pen pen = new Pen(foreground, 1.4f))
            {
                int cx = Width / 2;
                int cy = Height / 2;
                if (Glyph == WindowGlyph.Minimize)
                {
                    e.Graphics.DrawLine(pen, cx - 5, cy + 1, cx + 5, cy + 1);
                }
                else if (Glyph == WindowGlyph.Maximize)
                {
                    e.Graphics.DrawRectangle(pen, cx - 5, cy - 4, 10, 9);
                }
                else if (Glyph == WindowGlyph.Restore)
                {
                    e.Graphics.DrawRectangle(pen, cx - 4, cy - 5, 8, 8);
                    e.Graphics.DrawLine(pen, cx - 2, cy - 2, cx + 5, cy - 2);
                    e.Graphics.DrawLine(pen, cx + 5, cy - 2, cx + 5, cy + 4);
                    e.Graphics.DrawLine(pen, cx + 5, cy + 4, cx - 1, cy + 4);
                }
                else
                {
                    e.Graphics.DrawLine(pen, cx - 4, cy - 4, cx + 4, cy + 4);
                    e.Graphics.DrawLine(pen, cx + 4, cy - 4, cx - 4, cy + 4);
                }
            }
        }
    }

    internal sealed class LoadingBar : Control
    {
        private readonly Timer _timer;
        private float _phase;

        public LoadingBar()
        {
            SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
            Height = 3;
            BackColor = Theme.Background;
            _timer = new Timer();
            _timer.Interval = 16;
            _timer.Tick += delegate
            {
                _phase += 0.008f;
                if (_phase > 1.25f) _phase = -0.25f;
                Invalidate();
            };
        }

        public void Start()
        {
            _phase = -0.25f;
            _timer.Start();
            Invalidate();
        }

        public void Stop()
        {
            _timer.Stop();
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.Clear(Theme.Background);
            int segment = Math.Max(90, Width / 4);
            int x = (int)(_phase * (Width + segment));
            Rectangle bounds = new Rectangle(x, 0, segment, Height);
            using (LinearGradientBrush brush = new LinearGradientBrush(
                new Rectangle(bounds.X - 40, 0, bounds.Width + 80, Height),
                Color.FromArgb(0, Theme.Accent), Color.FromArgb(235, Theme.Accent), 0f))
            {
                e.Graphics.FillRectangle(brush, bounds);
            }
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                _timer.Stop();
                _timer.Dispose();
            }
            base.Dispose(disposing);
        }
    }

    internal sealed class LoadingOverlay : Panel
    {
        private readonly LoadingBar _bar;
        private readonly Label _label;

        public LoadingOverlay()
        {
            SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
            BackColor = Theme.Background;
            _bar = new LoadingBar();
            _bar.Dock = DockStyle.Top;
            _label = Theme.Label("Iniciando o Grape", 10f, Theme.Muted, false);
            _label.TextAlign = ContentAlignment.MiddleCenter;
            Controls.Add(_bar);
            Controls.Add(_label);
        }

        public void SetText(string text)
        {
            _label.Text = text ?? string.Empty;
        }

        public void Start()
        {
            _bar.Start();
        }

        public void Stop()
        {
            _bar.Stop();
        }

        protected override void OnResize(EventArgs e)
        {
            base.OnResize(e);
            _label.SetBounds(0, Math.Max(24, (Height / 2) - 12), Width, 24);
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            using (SolidBrush one = new SolidBrush(Color.FromArgb(22, 138, 63, 224)))
                e.Graphics.FillEllipse(one, -180, -170, 520, 520);
            using (SolidBrush two = new SolidBrush(Color.FromArgb(14, 178, 79, 240)))
                e.Graphics.FillEllipse(two, Width - 300, Height - 280, 460, 460);
            base.OnPaint(e);
        }
    }

    internal sealed class ErrorOverlay : Panel
    {
        private readonly Panel _card;
        private readonly Label _title;
        private readonly Label _detail;
        private readonly RoundedButton _retry;
        private readonly RoundedButton _logs;
        private readonly RoundedButton _cert;

        public event EventHandler RetryClicked;
        public event EventHandler LogsClicked;
        public event EventHandler CertificateClicked;

        public ErrorOverlay()
        {
            SetStyle(ControlStyles.UserPaint | ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.ResizeRedraw, true);
            BackColor = Theme.Background;
            _card = new Panel();
            _card.BackColor = Theme.Panel;
            _card.Paint += delegate(object sender, PaintEventArgs e)
            {
                e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
                using (GraphicsPath path = Theme.RoundedPath(new Rectangle(0, 0, _card.Width - 1, _card.Height - 1), 16))
                using (Pen pen = new Pen(Theme.Border)) e.Graphics.DrawPath(pen, path);
            };

            _title = Theme.Label(string.Empty, 18f, Theme.Text, true);
            _title.SetBounds(28, 24, 504, 36);
            _detail = Theme.Label(string.Empty, 9.5f, Theme.Muted, false);
            _detail.SetBounds(28, 68, 504, 132);

            _retry = (RoundedButton)Theme.Primary("Tentar novamente");
            _retry.SetBounds(28, 222, 118, 38);
            _logs = (RoundedButton)Theme.Secondary("Abrir registros", Theme.Border);
            _logs.SetBounds(158, 222, 112, 38);
            _cert = (RoundedButton)Theme.Secondary("Instalar certificado", Theme.Border);
            _cert.SetBounds(282, 222, 174, 38);

            _retry.Click += delegate { if (RetryClicked != null) RetryClicked(this, EventArgs.Empty); };
            _logs.Click += delegate { if (LogsClicked != null) LogsClicked(this, EventArgs.Empty); };
            _cert.Click += delegate { if (CertificateClicked != null) CertificateClicked(this, EventArgs.Empty); };

            _card.Controls.Add(_title);
            _card.Controls.Add(_detail);
            _card.Controls.Add(_retry);
            _card.Controls.Add(_logs);
            _card.Controls.Add(_cert);
            Controls.Add(_card);
            Resize += delegate { LayoutCard(); };
        }

        public void SetContent(string title, string detail, bool showCertificate)
        {
            _title.Text = title ?? string.Empty;
            _detail.Text = detail ?? string.Empty;
            _cert.Visible = showCertificate;
            LayoutCard();
        }

        private void LayoutCard()
        {
            int width = Math.Max(320, Math.Min(560, Width - 32));
            int height = 290;
            int left = Math.Max(16, (Width - width) / 2);
            int top = Math.Max(16, (Height - height) / 2);
            _card.SetBounds(left, top, width, height);
            _title.Width = width - 56;
            _detail.Width = width - 56;
            try
            {
                using (GraphicsPath path = Theme.RoundedPath(new Rectangle(0, 0, _card.Width, _card.Height), 16))
                    _card.Region = new Region(path);
            }
            catch { }
        }
    }

    internal sealed class MainForm : Form
    {
        private const int WM_NCLBUTTONDOWN = 0xA1;
        private const int WM_NCHITTEST = 0x84;
        private const int WM_GETMINMAXINFO = 0x24;
        private const int HTCLIENT = 1;
        private const int HTCAPTION = 2;
        private const int HTLEFT = 10;
        private const int HTRIGHT = 11;
        private const int HTTOP = 12;
        private const int HTTOPLEFT = 13;
        private const int HTTOPRIGHT = 14;
        private const int HTBOTTOM = 15;
        private const int HTBOTTOMLEFT = 16;
        private const int HTBOTTOMRIGHT = 17;

        [StructLayout(LayoutKind.Sequential)]
        private struct POINT
        {
            public int X;
            public int Y;
        }

        [StructLayout(LayoutKind.Sequential)]
        private struct MINMAXINFO
        {
            public POINT ptReserved;
            public POINT ptMaxSize;
            public POINT ptMaxPosition;
            public POINT ptMinTrackSize;
            public POINT ptMaxTrackSize;
        }

        [DllImport("user32.dll")]
        private static extern bool ReleaseCapture();

        [DllImport("user32.dll")]
        private static extern IntPtr SendMessage(IntPtr hWnd, int msg, IntPtr wParam, IntPtr lParam);

        private readonly LauncherContext _context;
        private TableLayoutPanel _shell;
        private Panel _titleBar;
        private Panel _content;
        private PictureBox _logo;
        private Label _appName;
        private RoundedButton _certButton;
        private WindowButton _minButton;
        private WindowButton _maxButton;
        private WindowButton _closeButton;
        private WebView2 _webView;
        private LoadingOverlay _loading;
        private ErrorOverlay _error;
        private ToolTip _tips;
        private bool _startupStarted;
        private bool _startupInProgress;
        private bool _servicesReady;
        private bool _webViewInitialized;
        private bool _closing;
        private bool _allowClose;
        private bool _installing;

        public MainForm(LauncherContext context)
        {
            _context = context;
            Theme.Initialize(context.Root);
            Text = "Grape";
            FormBorderStyle = FormBorderStyle.None;
            StartPosition = FormStartPosition.CenterScreen;
            Rectangle workArea = Screen.PrimaryScreen.WorkingArea;
            int initialWidth = Math.Min(1280, Math.Max(860, workArea.Width - 80));
            int initialHeight = Math.Min(820, Math.Max(580, workArea.Height - 80));
            ClientSize = new Size(initialWidth, initialHeight);
            MinimumSize = new Size(860, 580);
            BackColor = Theme.Background;
            ForeColor = Theme.Text;
            Font = Theme.Ui(9.5f);
            AutoScaleMode = AutoScaleMode.Dpi;
            Padding = new Padding(4);
            DoubleBuffered = true;
            SetStyle(ControlStyles.ResizeRedraw | ControlStyles.OptimizedDoubleBuffer, true);
            try
            {
                Icon appIcon = Icon.ExtractAssociatedIcon(Application.ExecutablePath);
                if (appIcon != null) Icon = appIcon;
            }
            catch { }

            BuildShell();
            Shown += OnShown;
            FormClosing += OnFormClosing;
            FormClosed += OnFormClosed;
            Resize += OnFormResize;
        }

        private void BuildShell()
        {
            _shell = new TableLayoutPanel();
            _shell.Dock = DockStyle.Fill;
            _shell.BackColor = Theme.Background;
            _shell.Margin = Padding.Empty;
            _shell.Padding = Padding.Empty;
            _shell.ColumnCount = 1;
            _shell.RowCount = 2;
            _shell.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100f));
            _shell.RowStyles.Add(new RowStyle(SizeType.Absolute, 44f));
            _shell.RowStyles.Add(new RowStyle(SizeType.Percent, 100f));

            _titleBar = new Panel();
            _titleBar.Dock = DockStyle.Fill;
            _titleBar.BackColor = Theme.TitleBar;
            _titleBar.Margin = Padding.Empty;
            _titleBar.Paint += PaintTitleBar;
            _titleBar.MouseDown += TitleMouseDown;

            BuildTitleBar();

            _content = new Panel();
            _content.Dock = DockStyle.Fill;
            _content.BackColor = Theme.Background;
            _content.Margin = Padding.Empty;

            _webView = new WebView2();
            _webView.Dock = DockStyle.Fill;
            _webView.DefaultBackgroundColor = Theme.Background;
            _webView.Visible = false;

            _loading = new LoadingOverlay();
            _loading.Dock = DockStyle.Fill;
            _loading.Visible = false;

            _error = new ErrorOverlay();
            _error.Dock = DockStyle.Fill;
            _error.Visible = false;
            _error.RetryClicked += OnRetryClicked;
            _error.LogsClicked += OnLogsClicked;
            _error.CertificateClicked += OnCertificateClicked;

            _content.Controls.Add(_webView);
            _content.Controls.Add(_loading);
            _content.Controls.Add(_error);

            _shell.Controls.Add(_titleBar, 0, 0);
            _shell.Controls.Add(_content, 0, 1);
            Controls.Add(_shell);
        }

        private void BuildTitleBar()
        {
            _logo = new PictureBox();
            _logo.SizeMode = PictureBoxSizeMode.Zoom;
            _logo.BackColor = Color.Transparent;
            try
            {
                string logoPath = Path.Combine(_context.Root, "public", "assets", "img", "logo-grape.png");
                if (File.Exists(logoPath)) _logo.Image = Image.FromFile(logoPath);
            }
            catch { }
            _logo.MouseDown += TitleMouseDown;
            _titleBar.Controls.Add(_logo);

            _appName = Theme.Label("grape", 13f, Theme.Text, true);
            _appName.TextAlign = ContentAlignment.MiddleLeft;
            _appName.MouseDown += TitleMouseDown;
            _titleBar.Controls.Add(_appName);

            _certButton = (RoundedButton)Theme.Secondary("Instalar certificado", Theme.Border);
            _certButton.CornerRadius = 8;
            _certButton.Font = Theme.Ui(8.5f);
            _certButton.Height = 30;
            _certButton.Visible = false;
            _certButton.Click += OnCertificateClicked;
            _titleBar.Controls.Add(_certButton);

            _minButton = new WindowButton(WindowGlyph.Minimize);
            _maxButton = new WindowButton(WindowGlyph.Maximize);
            _closeButton = new WindowButton(WindowGlyph.Close);
            _minButton.Click += delegate { WindowState = FormWindowState.Minimized; };
            _maxButton.Click += delegate { ToggleMaximize(); };
            _closeButton.Click += delegate { Close(); };
            _titleBar.Controls.Add(_minButton);
            _titleBar.Controls.Add(_maxButton);
            _titleBar.Controls.Add(_closeButton);

            _tips = new ToolTip();
            _tips.SetToolTip(_minButton, "Minimizar");
            _tips.SetToolTip(_maxButton, "Maximizar");
            _tips.SetToolTip(_closeButton, "Fechar");
            _tips.SetToolTip(_certButton, "Confiar no certificado HTTPS local do Grape");

            _titleBar.Resize += delegate { LayoutTitleBar(); };
            LayoutTitleBar();
        }

        private void LayoutTitleBar()
        {
            if (_titleBar == null || _closeButton == null) return;
            if (_titleBar.Width < 320 || _titleBar.Height < 24) return;
            int right = _titleBar.Width;
            int height = _titleBar.Height;
            _closeButton.SetBounds(right - 44, 0, 44, height);
            _maxButton.SetBounds(right - 88, 0, 44, height);
            _minButton.SetBounds(right - 132, 0, 44, height);
            _certButton.SetBounds(Math.Max(16, right - 132 - 12 - 174), Math.Max(0, (height - 30) / 2), 174, 30);
            _logo.SetBounds(16, Math.Max(0, (height - 24) / 2), 24, 24);
            _appName.SetBounds(48, 0, 260, height);
        }

        private void PaintTitleBar(object sender, PaintEventArgs e)
        {
            using (LinearGradientBrush brush = new LinearGradientBrush(_titleBar.ClientRectangle, Theme.TitleBar, Theme.TitleBarEnd, 0f))
                e.Graphics.FillRectangle(brush, _titleBar.ClientRectangle);
            using (Pen pen = new Pen(Theme.BorderSoft))
                e.Graphics.DrawLine(pen, 0, _titleBar.Height - 1, _titleBar.Width, _titleBar.Height - 1);
        }

        private void TitleMouseDown(object sender, MouseEventArgs e)
        {
            if (_closing || e.Button != MouseButtons.Left) return;
            if (e.Clicks == 2)
            {
                ToggleMaximize();
                return;
            }
            BeginDrag();
        }

        private void BeginDrag()
        {
            try
            {
                ReleaseCapture();
                SendMessage(Handle, WM_NCLBUTTONDOWN, (IntPtr)HTCAPTION, IntPtr.Zero);
            }
            catch { }
        }

        private void ToggleMaximize()
        {
            if (WindowState == FormWindowState.Maximized)
            {
                WindowState = FormWindowState.Normal;
            }
            else
            {
                MaximizedBounds = Screen.FromControl(this).WorkingArea;
                WindowState = FormWindowState.Maximized;
            }
            UpdateMaxGlyph();
        }

        private void UpdateMaxGlyph()
        {
            if (_maxButton == null) return;
            _maxButton.Glyph = WindowState == FormWindowState.Maximized ? WindowGlyph.Restore : WindowGlyph.Maximize;
            _maxButton.Invalidate();
        }

        private void OnFormResize(object sender, EventArgs e)
        {
            Padding = WindowState == FormWindowState.Maximized ? Padding.Empty : new Padding(4);
            UpdateMaxGlyph();
            LayoutTitleBar();
        }

        protected override void OnActivated(EventArgs e)
        {
            base.OnActivated(e);
            LayoutTitleBar();
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            base.OnPaint(e);
            using (Pen pen = new Pen(Theme.BorderSoft))
                e.Graphics.DrawRectangle(pen, 0, 0, Width - 1, Height - 1);
        }

        private async void OnShown(object sender, EventArgs e)
        {
            if (_startupStarted) return;
            _startupStarted = true;
            await StartAsync();
        }

        private async Task StartAsync()
        {
            if (_closing || _startupInProgress) return;
            _startupInProgress = true;
            try
            {
                HideError();
                ShowLoading("Iniciando o Grape");
                Task<bool> serviceTask = Task.Run(new Func<bool>(_context.StartServices));
                Exception webError = null;
                try { await InitializeWebViewAsync(); }
                catch (Exception ex) { webError = ex; Log.Exception("Could not initialize WebView2", ex); }

                bool servicesOk = false;
                Exception serviceError = null;
                try { servicesOk = await serviceTask; }
                catch (Exception ex) { serviceError = ex; Log.Exception("Service startup failed", ex); }
                if (_closing) return;

                _servicesReady = servicesOk;
                UpdateCertUi();
                if (!servicesOk)
                {
                    ShowError("Não foi possível iniciar o Grape", ServiceErrorText(serviceError), false);
                    return;
                }
                if (webError != null)
                {
                    ShowError("Não foi possível abrir a janela do Grape", WebViewErrorText(webError), false);
                    return;
                }

                await HandleCertificateAsync();
                if (_closing) return;
                NavigateApp();
            }
            catch (Exception ex)
            {
                Log.Exception("Unexpected startup failure", ex);
                if (!_closing) ShowError("Não foi possível iniciar o Grape", Shorten(ex.Message, 700), false);
            }
            finally
            {
                _startupInProgress = false;
            }
        }

        private async Task InitializeWebViewAsync()
        {
            if (_webViewInitialized) return;
            if (_webView == null) throw new InvalidOperationException("A visualização da área de trabalho não está disponível.");

            string runtime = Path.Combine(_context.Root, "runtime", "webview2");
            string bundled = Path.Combine(runtime, "msedgewebview2.exe");
            string browserFolder = File.Exists(bundled) ? runtime : null;
            string userData = Path.Combine(_context.Root, "data", "webview2");
            Directory.CreateDirectory(userData);
            if (browserFolder != null) Log.Write("Using bundled WebView2 runtime: " + runtime);
            else Log.Write("Bundled WebView2 runtime not found; using the installed Evergreen runtime.");

            CoreWebView2Environment environment = null;
            Exception bundledError = null;
            if (browserFolder != null)
            {
                try { environment = await CoreWebView2Environment.CreateAsync(browserFolder, userData, null); }
                catch (Exception ex) { bundledError = ex; Log.Exception("Bundled WebView2 runtime failed", ex); }
            }
            if (environment == null)
            {
                try { environment = await CoreWebView2Environment.CreateAsync(null, userData, null); }
                catch (Exception ex)
                {
                    if (bundledError != null) throw new InvalidOperationException("O WebView2 incluído falhou e não há um WebView2 instalado disponível.", ex);
                    throw new InvalidOperationException("O WebView2 não está disponível neste computador. Instale o Microsoft Edge WebView2 Runtime e tente novamente.", ex);
                }
            }
            if (_closing) return;
            await _webView.EnsureCoreWebView2Async(environment);
            if (_closing) return;
            CoreWebView2 core = _webView.CoreWebView2;
            if (core == null) throw new InvalidOperationException("O WebView2 não criou uma sessão de navegador.");

            core.Settings.AreDefaultContextMenusEnabled = false;
            core.Settings.AreDevToolsEnabled = false;
            core.Settings.IsStatusBarEnabled = false;
            core.Settings.IsBuiltInErrorPageEnabled = false;
            core.Settings.IsSwipeNavigationEnabled = false;
            core.Settings.IsPinchZoomEnabled = false;
            core.Settings.IsGeneralAutofillEnabled = false;
            core.Settings.IsPasswordAutosaveEnabled = false;
            core.Settings.AreBrowserAcceleratorKeysEnabled = true;
            core.Settings.IsZoomControlEnabled = true;
            core.Settings.AreDefaultScriptDialogsEnabled = true;
            core.Settings.IsWebMessageEnabled = false;
            core.Settings.AreHostObjectsAllowed = false;

            core.NavigationStarting += OnNavigationStarting;
            core.NavigationCompleted += OnNavigationCompleted;
            core.NewWindowRequested += OnNewWindowRequested;
            core.PermissionRequested += OnPermissionRequested;
            core.ProcessFailed += OnProcessFailed;

            _webViewInitialized = true;
            Log.Write("WebView2 ready: " + environment.BrowserVersionString + " / user data: " + userData);
        }

        private void OnNavigationStarting(object sender, CoreWebView2NavigationStartingEventArgs e)
        {
            if (_closing) return;
            if (string.IsNullOrEmpty(e.Uri) || e.Uri == "about:blank") return;
            if (IsAppUri(e.Uri)) return;
            Log.Write("Blocked external navigation: " + e.Uri);
            e.Cancel = true;
        }

        private void OnNavigationCompleted(object sender, CoreWebView2NavigationCompletedEventArgs e)
        {
            if (_closing) return;
            if (e.WebErrorStatus == CoreWebView2WebErrorStatus.OperationCanceled) return;
            if (e.IsSuccess)
            {
                _webView.Visible = true;
                HideLoading();
                _error.Visible = false;
                Log.Write("Page loaded: " + AppInfo.LocalUrl);
                return;
            }
            _webView.Visible = false;
            string detail = "A página local em " + AppInfo.LocalUrl + " não carregou." + Environment.NewLine + Environment.NewLine;
            detail += "O WebView2 informou: " + e.WebErrorStatus + ".";
            if (IsCertificateStatus(e.WebErrorStatus))
            {
                detail += Environment.NewLine + Environment.NewLine;
                detail += "O Windows ainda não confia no certificado HTTPS local do Grape. Instale-o e tente novamente.";
            }
            detail += Environment.NewLine + Environment.NewLine + "Registros: " + _context.LogsDir;
            ShowError("Não foi possível carregar a página do Grape", detail, _context.CertificateKnown && !_context.CertificateInstalled);
        }

        private void OnNewWindowRequested(object sender, CoreWebView2NewWindowRequestedEventArgs e)
        {
            e.Handled = true;
            if (IsAppUri(e.Uri) && _webView != null && _webView.CoreWebView2 != null)
            {
                _webView.CoreWebView2.Navigate(e.Uri);
            }
            else if (!string.IsNullOrEmpty(e.Uri))
            {
                Log.Write("Blocked external window: " + e.Uri);
            }
        }

        private void OnPermissionRequested(object sender, CoreWebView2PermissionRequestedEventArgs e)
        {
            if (!IsAppUri(e.Uri))
            {
                e.State = CoreWebView2PermissionState.Deny;
                return;
            }
            if (e.PermissionKind == CoreWebView2PermissionKind.Camera ||
                e.PermissionKind == CoreWebView2PermissionKind.Microphone ||
                e.PermissionKind == CoreWebView2PermissionKind.ClipboardRead ||
                e.PermissionKind == CoreWebView2PermissionKind.Autoplay)
            {
                e.State = CoreWebView2PermissionState.Allow;
            }
            else
            {
                e.State = CoreWebView2PermissionState.Deny;
            }
        }

        private void OnProcessFailed(object sender, CoreWebView2ProcessFailedEventArgs e)
        {
            if (_closing) return;
            Log.Write("WebView2 process failed: " + e.ProcessFailedKind);
            ShowError("A janela do Grape parou",
                "O processo do navegador integrado foi encerrado (" + e.ProcessFailedKind + ")." + Environment.NewLine + Environment.NewLine +
                "Tente novamente. Se continuar acontecendo, feche e abra o Grape." + Environment.NewLine + Environment.NewLine +
                "Registros: " + _context.LogsDir, false);
        }

        private bool IsAppUri(string uri)
        {
            try
            {
                Uri parsed = new Uri(uri);
                if (parsed.Scheme != "https") return false;
                if (parsed.IsLoopback) return true;
                return !string.IsNullOrEmpty(_context.RadminIp) &&
                    string.Equals(parsed.Host, _context.RadminIp, StringComparison.OrdinalIgnoreCase);
            }
            catch { return false; }
        }

        private static bool IsCertificateStatus(CoreWebView2WebErrorStatus status)
        {
            return status.ToString().IndexOf("Certificate", StringComparison.OrdinalIgnoreCase) >= 0;
        }

        private void NavigateApp()
        {
            if (_closing) return;
            if (!_webViewInitialized || _webView == null || _webView.CoreWebView2 == null)
            {
                ShowError("Não foi possível abrir a janela do Grape", "O navegador integrado não está pronto." + Environment.NewLine + Environment.NewLine + "Registros: " + _context.LogsDir, false);
                return;
            }
            HideError();
            ShowLoading("Carregando o Grape");
            try
            {
                _webView.CoreWebView2.Navigate(AppInfo.LocalUrl);
            }
            catch (Exception ex)
            {
                Log.Exception("Could not navigate WebView2", ex);
                ShowError("Não foi possível carregar a página do Grape", Shorten(ex.Message, 700) + Environment.NewLine + Environment.NewLine + "Registros: " + _context.LogsDir, false);
            }
        }

        private async Task HandleCertificateAsync()
        {
            UpdateCertUi();
            if (_context.CertificateInstalled)
            {
                if (_context.FirstRun) _context.MarkFirstRunCompleted("already-trusted");
                return;
            }
            if (!_context.CertificateKnown) return;
            if (!_context.FirstRun) return;

            Log.Write("Certificate prompt opening: known=" + _context.CertificateKnown + "; installed=" + _context.CertificateInstalled + "; firstRun=" + _context.FirstRun);
            bool accepted = false;
            DialogResult promptResult = DialogResult.None;
            using (CaPromptForm prompt = new CaPromptForm(_context))
            {
                promptResult = prompt.ShowDialog(this);
                accepted = promptResult == DialogResult.Yes;
            }
            Log.Write("Certificate prompt result: " + promptResult + "; accepted=" + accepted);
            if (!accepted)
            {
                _context.MarkFirstRunCompleted("declined");
                UpdateCertUi();
                MessageBox.Show(this,
                    "O certificado não foi instalado. O Grape vai tentar carregar, mas a janela pode exibir um erro de certificado." + Environment.NewLine + Environment.NewLine +
                    "Use Instalar certificado na barra de título quando quiser corrigir isso.",
                    "Certificado não instalado", MessageBoxButtons.OK, MessageBoxIcon.Information);
                return;
            }

            CaInstallResult result = null;
            try { result = await InstallCertificateWithBusyAsync(); }
            catch (Exception ex) { Log.Exception("Certificate installation failed", ex); }
            if (_closing) return;
            if (result != null && result.Success)
            {
                _context.MarkFirstRunCompleted("installed");
                UpdateCertUi();
                return;
            }
            string failure = result == null ? "Não foi possível iniciar o instalador." : result.Describe();
            MessageBox.Show(this,
                "O certificado não foi instalado." + Environment.NewLine + Environment.NewLine + failure + Environment.NewLine + Environment.NewLine +
                "A página ainda pode carregar, mas o acesso à câmera e ao microfone pode ficar bloqueado. Você pode tentar novamente pela barra de título.",
                "Falha ao instalar o certificado", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            UpdateCertUi();
        }

        private async Task<CaInstallResult> InstallCertificateWithBusyAsync()
        {
            BusyForm busy = new BusyForm("Instalando certificado", "O Windows pode pedir a confirmação do certificado. Esta janela fecha automaticamente quando o instalador terminar.");
            busy.Show(this);
            CaInstallResult result = null;
            try
            {
                result = await Task.Run(new Func<CaInstallResult>(_context.InstallCertificate));
            }
            finally
            {
                try { busy.Close(); } catch { }
                try { busy.Dispose(); } catch { }
            }
            if (!_closing) UpdateCertUi();
            return result;
        }

        private async void OnCertificateClicked(object sender, EventArgs e)
        {
            if (_closing || _installing || _startupInProgress) return;
            if (!_context.CertificateKnown) return;
            bool accepted = false;
            using (CaPromptForm prompt = new CaPromptForm(_context))
            {
                accepted = prompt.ShowDialog(this) == DialogResult.Yes;
            }
            if (!accepted || _closing) return;

            _installing = true;
            try
            {
                CaInstallResult result = null;
                try { result = await InstallCertificateWithBusyAsync(); }
                catch (Exception ex) { Log.Exception("Certificate installation failed", ex); }
                if (_closing) return;
                if (result != null && result.Success)
                {
                    if (_context.FirstRun) _context.MarkFirstRunCompleted("installed");
                    else _context.SaveCertificateState();
                    UpdateCertUi();
                    HideError();
                    NavigateApp();
                    return;
                }
                string failure = result == null ? "Não foi possível iniciar o instalador." : result.Describe();
                MessageBox.Show(this, "O certificado não foi instalado." + Environment.NewLine + Environment.NewLine + failure,
                    "Falha ao instalar o certificado", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                UpdateCertUi();
            }
            finally { _installing = false; }
        }

        private void UpdateCertUi()
        {
            if (_certButton == null) return;
            bool show = _context.CertificateKnown && !_context.CertificateInstalled;
            _certButton.Visible = show;
        }

        private void ShowLoading(string text)
        {
            if (_loading == null) return;
            _error.Visible = false;
            _loading.SetText(text);
            _loading.Visible = true;
            _loading.Start();
            _loading.BringToFront();
            if (_webView != null) _webView.Visible = false;
        }

        private void HideLoading()
        {
            if (_loading == null) return;
            _loading.Stop();
            _loading.Visible = false;
        }

        private void ShowError(string title, string detail, bool showCertificate)
        {
            if (_loading != null)
            {
                _loading.Stop();
                _loading.Visible = false;
            }
            if (_webView != null) _webView.Visible = false;
            _error.SetContent(title, Shorten(detail, 900), showCertificate);
            _error.Visible = true;
            _error.BringToFront();
            Log.Write("Error shown: " + title + " / " + Shorten(detail, 300));
        }

        private void HideError()
        {
            if (_error != null) _error.Visible = false;
        }

        private string ServiceErrorText(Exception exception)
        {
            string detail = _context.LastError;
            if (string.IsNullOrEmpty(detail) && exception != null) detail = exception.Message;
            if (string.IsNullOrEmpty(detail)) detail = "O PHP, o banco de dados ou o servidor web local não iniciou.";
            return Shorten(detail, 800) + Environment.NewLine + Environment.NewLine + "Registros: " + _context.LogsDir;
        }

        private string WebViewErrorText(Exception exception)
        {
            string detail = exception == null ? "O WebView2 não está disponível neste computador." : exception.Message;
            return Shorten(detail, 700) + Environment.NewLine + Environment.NewLine +
                "Esta versão inclui o WebView2 em runtime\\webview2. Se a pasta estiver faltando, reinstale o Grape ou instale o Microsoft Edge WebView2 Runtime." + Environment.NewLine + Environment.NewLine +
                "Registros: " + _context.LogsDir;
        }

        private async void OnRetryClicked(object sender, EventArgs e)
        {
            if (_closing || _startupInProgress) return;
            HideError();
            if (!_servicesReady)
            {
                await StartAsync();
                return;
            }
            if (!_webViewInitialized)
            {
                ShowLoading("Preparando o Grape");
                try { await InitializeWebViewAsync(); }
                catch (Exception ex) { Log.Exception("WebView2 retry failed", ex); ShowError("Não foi possível abrir a janela do Grape", WebViewErrorText(ex), false); return; }
                if (!_webViewInitialized) return;
            }
            if (_context.FirstRun) await HandleCertificateAsync();
            if (!_closing) NavigateApp();
        }

        private void OnLogsClicked(object sender, EventArgs e)
        {
            _context.OpenLogs();
        }

        private static string Shorten(string text, int max)
        {
            if (string.IsNullOrEmpty(text)) return string.Empty;
            string value = text.Replace("\r\n", "\n").Replace('\r', '\n').Replace("\n", Environment.NewLine);
            if (value.Length <= max) return value;
            return value.Substring(0, Math.Max(0, max - 3)) + "...";
        }

        private async void OnFormClosing(object sender, FormClosingEventArgs e)
        {
            Log.Write("Close requested: reason=" + e.CloseReason + "; allowClose=" + _allowClose + "; closing=" + _closing);
            if (_allowClose) return;
            if (e.CloseReason == CloseReason.WindowsShutDown)
            {
                _closing = true;
                return;
            }
            e.Cancel = true;
            if (_closing) return;
            _closing = true;
            if (_closeButton != null) _closeButton.Enabled = false;
            if (_minButton != null) _minButton.Enabled = false;
            if (_maxButton != null) _maxButton.Enabled = false;
            if (_certButton != null) _certButton.Enabled = false;
            ShowLoading("Fechando o Grape");
            try { await Task.Run(new Action(_context.StopServices)); }
            catch (Exception ex) { Log.Exception("Could not stop services cleanly", ex); }
            _allowClose = true;
            Close();
        }

        private void OnFormClosed(object sender, FormClosedEventArgs e)
        {
            Log.Write("Form closed: reason=" + e.CloseReason);
            try { if (_loading != null) _loading.Stop(); } catch { }
            try { if (_tips != null) _tips.Dispose(); } catch { }
            try { if (_webView != null) _webView.Dispose(); } catch { }
            try { _context.Dispose(); } catch { }
        }

        protected override void WndProc(ref Message m)
        {
            if (m.Msg == WM_GETMINMAXINFO)
            {
                base.WndProc(ref m);
                try
                {
                    MINMAXINFO info = (MINMAXINFO)Marshal.PtrToStructure(m.LParam, typeof(MINMAXINFO));
                    Rectangle work = Screen.FromControl(this).WorkingArea;
                    info.ptMaxPosition.X = work.Left;
                    info.ptMaxPosition.Y = work.Top;
                    info.ptMaxSize.X = work.Width;
                    info.ptMaxSize.Y = work.Height;
                    Marshal.StructureToPtr(info, m.LParam, false);
                }
                catch { }
                return;
            }
            if (m.Msg == WM_NCHITTEST)
            {
                base.WndProc(ref m);
                if ((int)m.Result == HTCLIENT && WindowState == FormWindowState.Normal)
                {
                    int screenX = unchecked((short)(long)m.LParam);
                    int screenY = unchecked((short)(((long)m.LParam >> 16) & 0xFFFF));
                    Point point = PointToClient(new Point(screenX, screenY));
                    int edge = 6;
                    bool left = point.X <= edge;
                    bool right = point.X >= ClientSize.Width - edge;
                    bool top = point.Y <= edge;
                    bool bottom = point.Y >= ClientSize.Height - edge;
                    if (left && top) m.Result = (IntPtr)HTTOPLEFT;
                    else if (right && top) m.Result = (IntPtr)HTTOPRIGHT;
                    else if (left && bottom) m.Result = (IntPtr)HTBOTTOMLEFT;
                    else if (right && bottom) m.Result = (IntPtr)HTBOTTOMRIGHT;
                    else if (left) m.Result = (IntPtr)HTLEFT;
                    else if (right) m.Result = (IntPtr)HTRIGHT;
                    else if (top) m.Result = (IntPtr)HTTOP;
                    else if (bottom) m.Result = (IntPtr)HTBOTTOM;
                }
                return;
            }
            base.WndProc(ref m);
        }
    }
}