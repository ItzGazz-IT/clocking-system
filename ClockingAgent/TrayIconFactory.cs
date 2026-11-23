using System.Drawing.Drawing2D;

public static class TrayIconFactory
{
    public static Icon Create()
    {
        using var bitmap = new Bitmap(32, 32);
        using var graphics = Graphics.FromImage(bitmap);
        graphics.SmoothingMode = SmoothingMode.AntiAlias;
        graphics.Clear(Color.Transparent);

        using var background = new SolidBrush(Color.FromArgb(24, 119, 242));
        graphics.FillEllipse(background, 2, 2, 28, 28);

        using var facePen = new Pen(Color.White, 3);
        graphics.DrawEllipse(facePen, 7, 7, 18, 18);

        using var handPen = new Pen(Color.White, 3)
        {
            StartCap = LineCap.Round,
            EndCap = LineCap.Round
        };
        graphics.DrawLine(handPen, 16, 16, 16, 10);
        graphics.DrawLine(handPen, 16, 16, 21, 18);

        using var checkPen = new Pen(Color.FromArgb(45, 212, 191), 4)
        {
            StartCap = LineCap.Round,
            EndCap = LineCap.Round
        };
        graphics.DrawLine(checkPen, 8, 24, 12, 28);
        graphics.DrawLine(checkPen, 12, 28, 24, 17);

        return Icon.FromHandle(bitmap.GetHicon());
    }
}
