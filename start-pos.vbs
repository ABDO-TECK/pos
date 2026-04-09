' Launches POS: Apache/MySQL (via batch) and Vite, then opens the browser.
' Paths follow this script's folder so the project can be moved.

Option Explicit

Dim shell, fso, base, bat
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
base = fso.GetParentFolderName(WScript.ScriptFullName)
bat = fso.BuildPath(base, "start-pos.bat")

shell.Run "cmd.exe /c """ & bat & """", 0, False
Set shell = Nothing
Set fso = Nothing
