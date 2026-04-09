Set WshShell = CreateObject("WScript.Shell")
WshShell.CurrentDirectory = "C:\prazno-watcher"
WshShell.Run "C:\prazno-watcher\start.bat", 0, False
