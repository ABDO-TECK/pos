Dim shell, desktop, shortcut
Set shell   = CreateObject("WScript.Shell")
desktop     = shell.SpecialFolders("Desktop")

Set shortcut              = shell.CreateShortcut(desktop & "\POS System.lnk")
shortcut.TargetPath       = "wscript.exe"
shortcut.Arguments        = """C:\xampp\htdocs\pos\start-pos.vbs"""
shortcut.WorkingDirectory = "C:\xampp\htdocs\pos"
shortcut.Description      = "Start POS System"
shortcut.IconLocation     = "C:\xampp\xampp-control.exe, 0"
shortcut.WindowStyle      = 7
shortcut.Save

MsgBox "تم إنشاء اختصار 'POS System' على سطح المكتب!", 64, "POS System"
