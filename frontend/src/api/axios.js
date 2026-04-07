import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  headers: { 'Content-Type': 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('pos_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (res) => res,
  (err) => {
    // Only force logout on 401 if we're NOT on the login endpoint itself
    if (err.response?.status === 401 && !err.config?.url?.includes('/login')) {
      localStorage.removeItem('pos_token')
      localStorage.removeItem('pos_auth')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export default api
