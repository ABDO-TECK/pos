import React from 'react'
import ReactDOM from 'react-dom/client'
import './store/themeStore'
import App from './App.jsx'
import './index.css'
import { initOfflineSync } from './utils/offlineSync.js'

initOfflineSync()

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
)
