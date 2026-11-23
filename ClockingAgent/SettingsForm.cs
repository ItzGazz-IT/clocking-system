using System.Windows.Forms;

public sealed class SettingsForm : Form
{
    private readonly TextBox _apiUrlText = new();
    private readonly TextBox _usernameText = new();
    private readonly TextBox _passwordText = new();
    private readonly CheckBox _runAtLoginCheck = new();
    private readonly Label _statusLabel = new();
    private readonly Label _linkedLabel = new();
    private readonly Button _linkButton = new();
    private readonly Button _testButton = new();
    private readonly Button _saveButton = new();
    private readonly Button _cancelButton = new();
    private string _deviceToken;
    private int _intervalSeconds;
    private bool _isLinked;
    private string _controlPasswordHash;

    public SettingsForm(AgentOptions options)
    {
        _isLinked = options.IsConfigured;
        _deviceToken = options.DeviceToken;
        _intervalSeconds = Math.Max(5, options.IntervalSeconds);
        _controlPasswordHash = options.ControlPasswordHash;
        Result = options.Copy();

        Text = "Clocking Agent Setup";
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        ClientSize = new Size(620, 390);
        BackColor = Color.White;

        Controls.Add(BuildLayout(options.ApiBaseUrl));
        AcceptButton = _isLinked ? _saveButton : _linkButton;
        CancelButton = _cancelButton;
        UpdateLinkedState();
    }

    public AgentOptions Result { get; private set; }

    private Control BuildLayout(string apiBaseUrl)
    {
        var root = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(24),
            ColumnCount = 1,
            RowCount = 6,
            BackColor = Color.White
        };
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 76));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 152));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 42));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 46));
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 46));

        var title = new Label
        {
            Text = "Link this computer",
            Dock = DockStyle.Fill,
            Font = new Font(Font.FontFamily, 18, FontStyle.Bold),
            ForeColor = Color.FromArgb(24, 31, 42)
        };
        var subtitle = new Label
        {
            Text = "Sign in once to register this Windows device for time and attendance.",
            Dock = DockStyle.Bottom,
            ForeColor = Color.FromArgb(82, 92, 105)
        };
        var header = new Panel { Dock = DockStyle.Fill, BackColor = Color.White };
        header.Controls.Add(title);
        header.Controls.Add(subtitle);

        var linkPanel = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 2,
            RowCount = 3,
            BackColor = Color.White
        };
        linkPanel.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 130));
        linkPanel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        linkPanel.RowStyles.Add(new RowStyle(SizeType.Absolute, 44));
        linkPanel.RowStyles.Add(new RowStyle(SizeType.Absolute, 44));
        linkPanel.RowStyles.Add(new RowStyle(SizeType.Absolute, 44));

        _apiUrlText.Text = apiBaseUrl;
        _apiUrlText.Dock = DockStyle.Fill;
        _usernameText.Dock = DockStyle.Fill;
        _passwordText.Dock = DockStyle.Fill;
        _passwordText.UseSystemPasswordChar = true;

        AddRow(linkPanel, 0, "Server URL", _apiUrlText);
        AddRow(linkPanel, 1, "Username", _usernameText);
        AddRow(linkPanel, 2, "Password", _passwordText);

        _linkedLabel.Dock = DockStyle.Fill;
        _linkedLabel.TextAlign = ContentAlignment.MiddleLeft;
        _linkedLabel.Font = new Font(Font.FontFamily, 9, FontStyle.Bold);

        _runAtLoginCheck.Text = "Start Time Attendance Agent when Windows starts";
        _runAtLoginCheck.Checked = true;
        _runAtLoginCheck.Enabled = false;
        _runAtLoginCheck.Dock = DockStyle.Fill;

        _statusLabel.AutoSize = false;
        _statusLabel.Dock = DockStyle.Fill;
        _statusLabel.TextAlign = ContentAlignment.MiddleLeft;
        _statusLabel.ForeColor = Color.FromArgb(82, 92, 105);

        var buttons = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = false,
            BackColor = Color.White
        };

        _linkButton.Text = "Link this device";
        _linkButton.AutoSize = true;
        _linkButton.Click += async (_, _) => await LinkDeviceAsync();

        _testButton.Text = "Test connection";
        _testButton.AutoSize = true;
        _testButton.Click += async (_, _) => await TestConnectionAsync();

        _saveButton.Text = "Save";
        _saveButton.AutoSize = true;
        _saveButton.Click += (_, _) => SaveAndClose();

        _cancelButton.Text = "Cancel";
        _cancelButton.AutoSize = true;
        _cancelButton.DialogResult = DialogResult.Cancel;

        buttons.Controls.Add(_saveButton);
        buttons.Controls.Add(_cancelButton);
        buttons.Controls.Add(_testButton);
        buttons.Controls.Add(_linkButton);

        root.Controls.Add(header, 0, 0);
        root.Controls.Add(linkPanel, 0, 1);
        root.Controls.Add(_linkedLabel, 0, 2);
        root.Controls.Add(_runAtLoginCheck, 0, 3);
        root.Controls.Add(_statusLabel, 0, 4);
        root.Controls.Add(buttons, 0, 5);

        return root;
    }

    private static void AddRow(TableLayoutPanel layout, int row, string label, Control input)
    {
        var labelControl = new Label
        {
            Text = label,
            AutoSize = false,
            Dock = DockStyle.Fill,
            TextAlign = ContentAlignment.MiddleLeft,
            ForeColor = Color.FromArgb(45, 55, 72)
        };
        layout.Controls.Add(labelControl, 0, row);
        layout.Controls.Add(input, 1, row);
    }

    private AgentOptions ReadOptions()
    {
        return new AgentOptions
        {
            ApiBaseUrl = _apiUrlText.Text.Trim(),
            DeviceToken = AgentSettingsStore.NormalizeToken(_deviceToken),
            IntervalSeconds = _intervalSeconds
            ,ControlPasswordHash = _controlPasswordHash
        };
    }

    private async Task LinkDeviceAsync()
    {
        var apiUrl = _apiUrlText.Text.Trim();
        var username = _usernameText.Text.Trim();
        var password = _passwordText.Text;

        if (apiUrl.Length == 0 || username.Length == 0 || password.Length == 0)
        {
            SetStatus("Enter the server URL, username, and password to link this device.", false);
            return;
        }

        SetBusy(true);
        SetStatus("Linking this device...", true);
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
            var result = await WindowsEventSender.EnrollDeviceAsync(http, apiUrl, username, password, timeout.Token);
            if (!result.Success)
            {
                SetStatus(result.Message, false);
                return;
            }

            _deviceToken = result.Token;
            _controlPasswordHash = PasswordProtection.Hash(password);
            _intervalSeconds = 60;
            _passwordText.Clear();
            _isLinked = true;
            UpdateLinkedState();
            SetStatus("Device linked. Click Save to keep these settings.", true);
        }
        catch (OperationCanceledException)
        {
            SetStatus("The server did not respond within 30 seconds. Check the URL and connection, then try again.", false);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private async Task TestConnectionAsync()
    {
        var options = ReadOptions();
        if (!options.IsConfigured)
        {
            SetStatus("Link this device before testing the connection.", false);
            return;
        }

        SetBusy(true);
        SetStatus("Testing connection...", true);
        try
        {
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
            var sender = new WindowsEventSender(http, options);
            var result = await sender.CheckEmployeeAsync(timeout.Token);
            SetStatus(result.Success ? "Connection OK. Device is linked to an employee." : result.Message, result.Success);
        }
        catch (OperationCanceledException)
        {
            SetStatus("The connection test timed out after 30 seconds.", false);
        }
        finally
        {
            SetBusy(false);
        }
    }

    private void SaveAndClose()
    {
        var options = ReadOptions();
        if (!options.IsConfigured)
        {
            SetStatus("Link this device before saving.", false);
            return;
        }

        try
        {
            AgentSettingsStore.Save(options);
            StartupManager.SetEnabled(true);
            Result = options;
            DialogResult = DialogResult.OK;
            Close();
        }
        catch (Exception ex)
        {
            SetStatus($"Could not save settings: {ex.Message}", false);
        }
    }

    private void UpdateLinkedState()
    {
        _linkedLabel.Text = _isLinked
            ? "Linked. You can test the connection or save these settings."
            : "Not linked yet. Link this device before testing or saving.";
        _linkedLabel.ForeColor = _isLinked ? Color.DarkGreen : Color.FromArgb(130, 89, 0);

        _linkButton.Enabled = true;
        _testButton.Enabled = _isLinked;
        _saveButton.Enabled = _isLinked;
        AcceptButton = _isLinked ? _saveButton : _linkButton;
    }

    private void SetBusy(bool busy)
    {
        _linkButton.Enabled = !busy;
        _testButton.Enabled = !busy && _isLinked;
        _saveButton.Enabled = !busy && _isLinked;
        _cancelButton.Enabled = !busy;
        UseWaitCursor = busy;
    }

    private void SetStatus(string message, bool success)
    {
        _statusLabel.ForeColor = success ? Color.DarkGreen : Color.Firebrick;
        _statusLabel.Text = message;
    }
}
