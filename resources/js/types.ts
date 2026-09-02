export interface User {
    id: number;
    name: string;
    email: string;
    created_at: string;
    updated_at: string;
}

export interface ValidationErrorResponse {
    message: string;
    errors: Record<string, string[]>;
}
