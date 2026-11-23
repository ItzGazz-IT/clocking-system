using Microsoft.Win32;
using System.Windows.Forms;

public static class StartupManager
{
    private const string RunKeyPath = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string AppName = "ClockingAgent";

    public static bool IsEnabled()
    {
        using var machineKey = Registry.LocalMachine.OpenSubKey(RunKeyPath, writable: false);
        if (machineKey?.GetValue(AppName) is string machineValue
            && machineValue.Contains("ClockingAgent.exe", StringComparison.OrdinalIgnoreCase))
        {
            return true;
        }

        using var key = Registry.CurrentUser.OpenSubKey(RunKeyPath, writable: false);
        return key?.GetValue(AppName) is string value
            && value.Contains("ClockingAgent.exe", StringComparison.OrdinalIgnoreCase);
    }

    public static void SetEnabled(bool enabled)
    {
        try
        {
            using var machineKey = Registry.LocalMachine.OpenSubKey(RunKeyPath, writable: true);
            if (machineKey is not null)
            {
                if (!enabled)
                {
                    machineKey.DeleteValue(AppName, throwOnMissingValue: false);
                    return;
                }

                machineKey.SetValue(AppName, $"\"{Application.ExecutablePath}\"");
                return;
            }
        }
        catch (UnauthorizedAccessException)
        {
            // Per-machine startup is controlled by the managed installer.
        }

        using var key = Registry.CurrentUser.OpenSubKey(RunKeyPath, writable: true)
            ?? Registry.CurrentUser.CreateSubKey(RunKeyPath, writable: true);

        if (!enabled)
        {
            key.DeleteValue(AppName, throwOnMissingValue: false);
            return;
        }

        key.SetValue(AppName, $"\"{Application.ExecutablePath}\"");
    }
}
