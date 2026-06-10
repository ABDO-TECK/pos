const { exec } = require('child_process');
const sudo = require('sudo-prompt');
const fs = require('fs');
const path = require('path');
const { app } = require('electron');

const FLAG_VERSION = 'configured-v3-edge-private';

function getFirewallFlagPath() {
  return path.join(app.getPath('userData'), 'firewall-configured.flag');
}

/**
 * Configure Windows Firewall rules + set Wi-Fi network to Private.
 *
 * Ensures incoming connections on ports 8080 (PHP) and 8443 (HTTPS proxy)
 * are allowed from any network profile, with edge traversal enabled for
 * Public networks. Also sets the active Wi-Fi connection to Private to
 * allow network discovery and incoming connections.
 */
function configureFirewall() {
  return new Promise((resolve) => {
    if (process.platform !== 'win32') {
      return resolve(true);
    }

    const flagPath = getFirewallFlagPath();
    const flagContent = fs.existsSync(flagPath) ? fs.readFileSync(flagPath, 'utf8') : '';
    if (flagContent === FLAG_VERSION) {
      console.log('[Firewall] Firewall rules already configured (v3).');
      return resolve(true);
    }

    try {
      const rulePhp = 'POS System - Web Port 8080';
      const ruleApp = 'POS System - SSL Port 8443';

      // 1. Delete all previous rule versions
      const cmdDelOldPhp = `netsh advfirewall firewall delete rule name="POS System - Embedded PHP"`;
      const cmdDelOldApp = `netsh advfirewall firewall delete rule name="POS System - Main Executable"`;
      const cmdDelPhp = `netsh advfirewall firewall delete rule name="${rulePhp}"`;
      const cmdDelApp = `netsh advfirewall firewall delete rule name="${ruleApp}"`;

      // 2. Add new rules with edge traversal enabled (critical for Public networks)
      const cmdAddPhp = `netsh advfirewall firewall add rule name="${rulePhp}" dir=in action=allow protocol=TCP localport=8080 enable=yes profile=any edge=yes`;
      const cmdAddApp = `netsh advfirewall firewall add rule name="${ruleApp}" dir=in action=allow protocol=TCP localport=8443 enable=yes profile=any edge=yes`;

      // 3. Set the Wi-Fi connection to Private network (allows network discovery)
      //    Uses PowerShell since netsh doesn't support network category changes.
      //    -ErrorAction SilentlyContinue means it won't fail if Wi-Fi isn't connected.
      const cmdSetPrivate = `powershell -Command "Get-NetConnectionProfile | Where-Object { $_.InterfaceAlias -like 'Wi-Fi*' -and $_.NetworkCategory -eq 'Public' } | Set-NetConnectionProfile -NetworkCategory Private -ErrorAction SilentlyContinue"`;

      const combinedCommand = [
        cmdDelOldPhp, cmdDelOldApp,
        cmdDelPhp, cmdDelApp,
        cmdAddPhp, cmdAddApp,
        cmdSetPrivate
      ].join(' & ');

      const options = {
        name: 'POS System'
      };

      console.log('[Firewall] Requesting UAC elevation to configure firewall + network profile...');
      sudo.exec(combinedCommand, options, (error, stdout, stderr) => {
        if (error) {
          console.error('[Firewall] Failed to configure firewall rules:', error.message);
          resolve(false);
        } else {
          console.log('[Firewall] Firewall rules + network profile configured successfully.');
          if (stdout) console.log('[Firewall] stdout:', stdout);
          fs.writeFileSync(flagPath, FLAG_VERSION, 'utf-8');
          resolve(true);
        }
      });
    } catch (err) {
      console.error('[Firewall] Error building firewall commands:', err.message);
      resolve(false);
    }
  });
}

module.exports = { configureFirewall };
