/**
 * SafeShutdown.js — Manages graceful shutdown and process lifecycle before installer execution.
 *
 * Prevents database corruption, file lock conflicts on Windows, and ensures all child
 * processes (PHP workers, MariaDB, QZ Tray) terminate cleanly.
 */

const fs = require('node:fs');
const path = require('node:path');
const { app, BrowserWindow } = require('electron');

class SafeShutdown {
  /**
   * Execute graceful shutdown sequence before launching installer.
   *
   * @param {Object} options
   * @param {string} [options.backupMarkerPath] Path to write shutdown marker
   * @param {Function} [options.stopRuntimeServices] Callback to stop PHP/MariaDB
   * @returns {Promise<boolean>}
   */
  static async prepareForInstall(options = {}) {
    try {
      // 1. Write final shutdown / backup marker
      const markerPath = options.backupMarkerPath || path.join(app.getPath('userData'), 'update-installer-staged.json');
      const markerData = {
        timestamp: new Date().toISOString(),
        version: app.getVersion(),
        status: 'ready_for_installer',
      };
      try {
        fs.writeFileSync(markerPath, JSON.stringify(markerData, null, 2), 'utf8');
      } catch (err) {
        console.warn('[SafeShutdown] Could not write marker:', err.message);
      }

      // 2. Stop child background services if provided
      if (typeof options.stopRuntimeServices === 'function') {
        try {
          await options.stopRuntimeServices();
        } catch (err) {
          console.warn('[SafeShutdown] Error stopping runtime services:', err.message);
        }
      }

      // 3. Close all open BrowserWindows
      const windows = BrowserWindow.getAllWindows();
      for (const win of windows) {
        try {
          if (!win.isDestroyed()) {
            win.removeAllListeners('close');
            win.close();
          }
        } catch (err) {
          console.warn('[SafeShutdown] Error closing window:', err.message);
        }
      }

      // Give 300ms for file handles and sockets to release on Windows
      await new Promise((resolve) => setTimeout(resolve, 300));

      return true;
    } catch (error) {
      console.error('[SafeShutdown] Safe shutdown failed:', error);
      return false;
    }
  }
}

module.exports = SafeShutdown;
