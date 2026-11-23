using System.Runtime.InteropServices;

public static class IdleTimeHelper
{
    [StructLayout(LayoutKind.Sequential)]
    private struct LASTINPUTINFO
    {
        public uint cbSize;
        public uint dwTime;
    }

    [DllImport("user32.dll")]
    private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

    public static int GetIdleSeconds()
    {
        var info = new LASTINPUTINFO();
        info.cbSize = (uint)Marshal.SizeOf(info);

        if (!GetLastInputInfo(ref info))
            return 0;

        uint idleMilliseconds = ((uint)Environment.TickCount - info.dwTime);
        return (int)(idleMilliseconds / 1000);
    }
}