import React from 'react'
import ReactDOM from 'react-dom/client'
import './store/themeStore'
import App from './App.jsx'
import './index.css'
import { initOfflineSync } from './utils/offlineSync.js'

initOfflineSync()

// منع تغيير الأرقام في حقول الإدخال عبر تحريك بكرة الماوس (scroll)
document.addEventListener('wheel', (e) => {
  if (document.activeElement.type === 'number') {
    document.activeElement.blur()
  }
})

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
)
