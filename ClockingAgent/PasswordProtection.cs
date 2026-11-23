using System.Security.Cryptography;

public static class PasswordProtection
{
    public static string Hash(string password)
    {
        var salt = RandomNumberGenerator.GetBytes(16);
        var hash = Rfc2898DeriveBytes.Pbkdf2(password, salt, 120_000, HashAlgorithmName.SHA256, 32);
        return $"{Convert.ToBase64String(salt)}.{Convert.ToBase64String(hash)}";
    }

    public static bool Verify(string password, string stored)
    {
        var parts = stored.Split('.', 2);
        if (parts.Length != 2) return false;
        try
        {
            var salt = Convert.FromBase64String(parts[0]);
            var expected = Convert.FromBase64String(parts[1]);
            var actual = Rfc2898DeriveBytes.Pbkdf2(password, salt, 120_000, HashAlgorithmName.SHA256, expected.Length);
            return CryptographicOperations.FixedTimeEquals(actual, expected);
        }
        catch (FormatException) { return false; }
    }
}

public sealed class PasswordPrompt : Form
{
    private readonly TextBox _password = new() { UseSystemPasswordChar = true, Dock = DockStyle.Top };
    public string Password => _password.Text;

    public PasswordPrompt(string action)
    {
        Text = "Administrator approval";
        StartPosition = FormStartPosition.CenterScreen;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false; MinimizeBox = false; ClientSize = new Size(360, 145);
        var label = new Label { Text = $"Enter the administrator password to {action}.", Dock = DockStyle.Top, Height = 42 };
        var ok = new Button { Text = "Continue", DialogResult = DialogResult.OK, Dock = DockStyle.Right, Width = 100 };
        var cancel = new Button { Text = "Cancel", DialogResult = DialogResult.Cancel, Dock = DockStyle.Right, Width = 90 };
        var buttons = new Panel { Dock = DockStyle.Bottom, Height = 42 }; buttons.Controls.Add(ok); buttons.Controls.Add(cancel);
        var panel = new Panel { Dock = DockStyle.Fill, Padding = new Padding(16) }; panel.Controls.Add(_password); panel.Controls.Add(label);
        Controls.Add(panel); Controls.Add(buttons); AcceptButton = ok; CancelButton = cancel;
    }
}
