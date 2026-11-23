#define MyAppName "Time Attendance Agent"
#define MyAppPublisher "Time Attendance Tracking"
#define MyAppExeName "ClockingAgent.exe"
#define MyAppVersion GetEnv("CLOCKING_AGENT_VERSION")

#if MyAppVersion == ""
  #define MyAppVersion "1.0.0"
#endif

[Setup]
AppId={{D8E66178-EB56-4E63-BE02-47B4F50E3E1B}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\Time Attendance Tracking\Agent
DefaultGroupName=Time Attendance Tracking
DisableDirPage=yes
DisableProgramGroupPage=yes
OutputDir=..\dist
OutputBaseFilename=Time-Attendance-Agent-Setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
SetupLogging=yes
UninstallDisplayName={#MyAppName}
CloseApplications=yes
RestartApplications=no

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Shortcuts:"; Flags: unchecked

[Files]
Source: "..\dist\ClockingAgent-self-contained\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Dirs]
Name: "{commonappdata}\Time Attendance Tracking\Agent"; Permissions: users-modify

[Icons]
Name: "{group}\Time Attendance Agent"; Filename: "{app}\{#MyAppExeName}"
Name: "{autodesktop}\Time Attendance Agent"; Filename: "{app}\{#MyAppExeName}"; Tasks: desktopicon

[Registry]
Root: HKLM; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "ClockingAgent"; ValueData: """{app}\{#MyAppExeName}"""

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Launch Time Attendance Agent"; Flags: nowait

[UninstallRun]
Filename: "{app}\{#MyAppExeName}"; Parameters: "--report-uninstall"; Flags: runhidden waituntilterminated; RunOnceId: "ReportClockingAgentUninstall"
Filename: "{cmd}"; Parameters: "/C taskkill /IM ClockingAgent.exe /F"; Flags: runhidden; RunOnceId: "StopClockingAgent"

[Code]
procedure CurUninstallStepChanged(CurUninstallStep: TUninstallStep);
begin
  if CurUninstallStep = usUninstall then
    RegDeleteValue(HKLM, 'Software\Microsoft\Windows\CurrentVersion\Run', 'ClockingAgent');
end;
