Dim shell
Set shell = CreateObject("WScript.Shell")
shell.Run "cmd.exe /c ""C:\xampp\htdocs\pos\start-pos.bat""", 0, False
Set shell = Nothing
