
// js/api-client.js

(function() {
    'use strict';
    
    class ApiClient {
        constructor() {
            // Получаем basePath из глобальной переменной или вычисляем
            this.basePath = window.BASE_PATH || this.detectBasePath();
            this.csrfToken = window.CSRF_TOKEN || '';
            
            console.log('ApiClient initialized with basePath:', this.basePath);
        }
        
        detectBasePath() {
            // Пробуем определить из текущего URL
            const path = window.location.pathname;
            const match = path.match(/^(\/[^\/]*)\//);
            return match ? match[1] : '';
        }
        
        getUrl(endpoint) {
            // Формируем URL с учетом basePath
            const base = this.basePath || '';
            const url = base + '/api/' + endpoint + '.php';
            console.log('API URL:', url);
            return url;
        }
        
        async request(endpoint, data = {}, method = 'POST') {
            const url = this.getUrl(endpoint);
            
            // Если данные - объект, конвертируем в FormData
            const body = data instanceof FormData ? data : this.toFormData(data);
            
            // Добавляем CSRF токен если есть
            if (this.csrfToken && !body.has('csrf_token')) {
                body.append('csrf_token', this.csrfToken);
            }
            
            // Добавляем fingerprint если есть
            const fingerprint = this.getFingerprint();
            if (fingerprint && !body.has('fingerprint')) {
                body.append('fingerprint', fingerprint);
            }
            
            const response = await fetch(url, {
                method: method,
                body: body,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error('HTTP error: ' + response.status);
            }
            
            return response.json();
        }
        
        toFormData(data) {
            const formData = new FormData();
            for (const [key, value] of Object.entries(data)) {
                if (value !== undefined && value !== null) {
                    formData.append(key, String(value));
                }
            }
            return formData;
        }
        
        // Специализированные методы
        async saveProgress(bookId, position, duration) {
            return this.request('bookmarks', {
                action: 'save_progress',
                book_id: bookId,
                cfi_range: position.cfi || '',
                page_number: position.page || 0,
                percentage: position.percentage || 0,
                duration: duration || 0
            });
        }
        
        async getLastRead(bookId) {
            return this.request('bookmarks', {
                action: 'get_last_read',
                book_id: bookId
            }, 'GET');
        }
        
        async createBookmark(bookId, position, note) {
            return this.request('bookmarks', {
                action: 'create',
                book_id: bookId,
                cfi_range: position.cfi || '',
                page_number: position.page || 0,
                percentage: position.percentage || 0,
                note: note || ''
            });
        }
        
        async deleteBookmark(bookmarkId) {
            return this.request('bookmarks', {
                action: 'delete',
                bookmark_id: bookmarkId
            });
        }
        
        getFingerprint() {
            const match = document.cookie.match(/device_fp=([^;]+)/);
            return match ? match[1] : null;
        }
    }
    
    // Создаем глобальный экземпляр
    window.ApiClient = ApiClient;
    window.api = new ApiClient();
    
})();
