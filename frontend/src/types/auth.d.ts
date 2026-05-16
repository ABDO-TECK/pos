declare global {
  interface User {
    id: number;
    name: string;
    username?: string;
    email: string;
    password?: string;
    role: string;
    force_password_change?: number;
    created_at?: string;
    updated_at?: string;
  }

  interface AuthResponse {
    user: User;
    message?: string;
    data?: User; // To allow res.data.data access
  }
}

export {};
