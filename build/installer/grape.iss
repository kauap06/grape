; Grape - instalador Inno Setup
; Compile com build\installer.ps1. Os caminhos abaixo são relativos a este arquivo.

#define MyAppName "Grape"
#define MyAppPublisher "Grape"
#define MyAppExeName "Grape.exe"
#define MyAppId "{{5B4C7C2E-6F5D-4B8A-9E1A-2D7F3C6B9A10}}"

#ifndef MyAppVersion
  #define MyAppVersion "1.0.1"
#endif
#ifndef PayloadDir
  #define PayloadDir "..\..\dist\Grape"
#endif
#ifndef OutputDir
  #define OutputDir "..\..\dist"
#endif
#ifndef Compression
  #define Compression "lzma2/max"
#endif

[Setup]
AppId={#MyAppId}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppVerName={#MyAppName} {#MyAppVersion}
AppPublisher={#MyAppPublisher}
AppCopyright={#MyAppPublisher}
VersionInfoVersion={#MyAppVersion}
VersionInfoCompany={#MyAppPublisher}
VersionInfoDescription=Instalador do Grape
VersionInfoProductName={#MyAppName}
VersionInfoProductVersion={#MyAppVersion}
DefaultDirName={localappdata}\Grape\app
DefaultGroupName={#MyAppName}
DisableProgramGroupPage=yes
DisableStartupPrompt=yes
DirExistsWarning=no
UsePreviousAppDir=yes
UsePreviousGroup=yes
UsePreviousTasks=yes
AllowNoIcons=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
MinVersion=10.0
OutputDir={#OutputDir}
OutputBaseFilename=GrapeSetup-{#MyAppVersion}
SetupIconFile=assets\grape.ico
UninstallDisplayIcon={app}\{#MyAppExeName}
Compression={#Compression}
SolidCompression=yes
WizardStyle=modern dynamic
WizardImageFile=assets\wizard-large.bmp,assets\wizard-large@2x.bmp
WizardSmallImageFile=assets\wizard-small.bmp,assets\wizard-small@2x.bmp
CloseApplications=no
RestartApplications=no
SetupLogging=yes

[Languages]
Name: "brazilianportuguese"; MessagesFile: "compiler:Languages\BrazilianPortuguese.isl"

[Messages]
SetupAppTitle=Grape
SetupWindowTitle=%1 - Instalador
UninstallAppTitle=Grape
UninstallAppFullTitle=Desinstalar o Grape
WelcomeLabel1=Bem-vindo
WelcomeLabel2=Vamos preparar o Grape.
WizardSelectDir=Destino
SelectDirDesc=Onde o Grape vai ficar.
SelectDirLabel3=O Grape será instalado nesta pasta.
SelectDirBrowseLabel=Clique em Avançar para continuar.
WizardSelectTasks=Atalhos
SelectTasksDesc=Onde o Grape deve aparecer.
SelectTasksLabel2=Escolha os atalhos.
WizardReady=Pronto
ReadyLabel1=O Grape está pronto para ser instalado.
ReadyLabel2a=Clique em Instalar para começar.
ReadyLabel2b=Clique em Instalar para começar.
WizardPreparing=Preparando
PreparingDesc=Preparando o Grape.
WizardInstalling=Instalando
InstallingLabel=Instalando o Grape...
FinishedHeadingLabel=Grape instalado
FinishedLabel=O Grape está pronto.
FinishedLabelNoIcons=O Grape está pronto.
ClickFinish=Clique em Concluir.
ClickNext=Clique em Avançar.
RunEntryExec=Executar %1

[Tasks]
Name: "desktopicon"; Description: "Atalho na área de trabalho"; GroupDescription: "Atalhos:"; Flags: unchecked

[Files]
Source: "{#PayloadDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs; Excludes: "LEIA-ME.txt,BUILD-INFO.txt,composer.json,composer.lock"

[Dirs]
Name: "{app}\data"; Flags: uninsneveruninstall
Name: "{app}\data\sessions"; Flags: uninsneveruninstall
Name: "{app}\logs"; Flags: uninsneveruninstall

[Icons]
Name: "{group}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; WorkingDir: "{app}"; IconFilename: "{app}\{#MyAppExeName}"; Comment: "Abrir o Grape"
Name: "{autodesktop}\{#MyAppName}"; Filename: "{app}\{#MyAppExeName}"; WorkingDir: "{app}"; IconFilename: "{app}\{#MyAppExeName}"; Comment: "Abrir o Grape"; Tasks: desktopicon

[Run]
Filename: "{app}\{#MyAppExeName}"; Description: "Abrir o Grape"; WorkingDir: "{app}"; Flags: nowait postinstall skipifsilent

[Code]
function PrepareToInstall(var NeedsRestart: Boolean): String;
begin
  if FindWindowByWindowName('Grape') <> 0 then
    Result := 'Feche o Grape para continuar.'
  else
    Result := '';
end;

function InitializeUninstall(): Boolean;
begin
  Result := True;
  if FindWindowByWindowName('Grape') <> 0 then
  begin
    MsgBox('Feche o Grape para continuar.', mbError, MB_OK);
    Result := False;
  end;
end;