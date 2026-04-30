import axios, { InternalAxiosRequestConfig, AxiosResponse, AxiosError } from 'axios'
import toast from 'react-hot-toast'

const api = axios.create({
  baseURL: '/api/v1',
  headers: { 'Content-Type': 'application/json' },
  withCredentials: true,
})

// Helper: read a cookie by name
function getCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
  return match ? decodeURIComponent(match[2]) : null
}

api.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem('pos_token')
  if (token && config.headers) {
    config.headers.Authorization = `Bearer ${token}`
  }
  
  // Attach XSRF-TOKEN header for CSRF protection
  const xsrf = getCookie('XSRF-TOKEN')
  if (xsrf && config.headers) {
    config.headers['X-XSRF-TOKEN'] = xsrf
  }
  
  return config
})

api.interceptors.response.use(
  (res: AxiosResponse) => res,
  (err: AxiosError) => {
    // Only force logout on 401 if we're NOT on the login endpoint itself
    if (err.response?.status === 401 && !err.config?.url?.includes('/login')) {
      localStorage.removeItem('pos_token')
      localStorage.removeItem('pos_auth')
      window.location.href = '/login'
      return Promise.reject(err)
    }

    // Handle 403 force_password_change — update the auth store silently
    const data = err.response?.data as any;
    if (err.response?.status === 403 && data?.errors?.force_password_change) {
      try {
        const raw = localStorage.getItem('pos_auth')
        if (raw) {
          const parsed = JSON.parse(raw)
          if (parsed?.state?.user) {
            parsed.state.user.force_password_change = 1
            localStorage.setItem('pos_auth', JSON.stringify(parsed))
            // Reload only once to pick up the new store state and show the modal
            if (!sessionStorage.getItem('fpc_reload')) {
              sessionStorage.setItem('fpc_reload', '1')
              window.location.reload()
            }
          }
        }
      } catch {}
      return Promise.reject(err)
    }

    // Skip global toast if explicitly requested via custom config
    if (err.config && (err.config as any).hideGlobalError) {
      return Promise.reject(err)
    }

    // Extract error message
    const message = data?.message || data?.error || 'حدث خطأ في الاتصال بالخادم';

    // Handle Validation Errors (422) specifically
    if (err.response?.status === 422 && data?.errors) {
      const firstError = Object.values(data.errors)[0] as string[];
      toast.error(firstError?.[0] || 'بيانات غير صحيحة');
    } else if (err.response?.status !== 401 && err.code !== 'ERR_CANCELED') {
      toast.error(message);
    }

    return Promise.reject(err)
  }
)

export default api
