' Creates a Desktop shortcut to start-pos.vbs (POS launcher).

Option Explicit

Dim shell, fso, base, desktop, shortcut, vbs
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
base = fso.GetParentFolderName(WScript.ScriptFullName)
vbs = fso.BuildPath(base, "start-pos.vbs")
desktop = shell.SpecialFolders("Desktop")

Set shortcut = shell.CreateShortcut(desktop & "\POS System.lnk")
shortcut.TargetPath = "wscript.exe"
shortcut.Arguments = """" & vbs & """"
shortcut.WorkingDirectory = base
shortcut.Description = "Start POS System (Apache, MySQL, Vite)"
shortcut.IconLocation = "C:\xampp\xampp-control.exe, 0"
shortcut.WindowStyle = 7
shortcut.Save

MsgBox "Shortcut 'POS System' was created on your Desktop.", 64, "POS System"

Set shortcut = Nothing
Set shell = Nothing
Set fso = Nothing
