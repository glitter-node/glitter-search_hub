import '../css/main.css';

export const identifier = 'glitter-search_hub';
export const version = '0.1.3';

export function initModule(): void {
    const api = (window as any).G7Core?.api;
    (window as any).GlitterSearchHub = {
        identifier,
        version,
        search: (params: URLSearchParams) => {
            const query = params.toString();
            return api?.get ? api.get(`/api/search${query ? `?${query}` : ''}`) : Promise.reject(new Error('G7Core API is unavailable'));
        },
    };
}

initModule();
