/**
 * SEO Publisher - 主JS
 */

// 全局配置
const APP = {
    baseUrl: '',
    
    // 显示加载
    showLoading() {
        const overlay = document.createElement('div');
        overlay.className = 'spinner-overlay';
        overlay.id = 'loadingOverlay';
        overlay.innerHTML = '<div class="spinner-border text-primary" style="width:3rem;height:3rem"></div>';
        document.body.appendChild(overlay);
    },

    // 隐藏加载
    hideLoading() {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) overlay.remove();
    },

    // Toast通知
    toast(message, type = 'success') {
        const container = document.querySelector('.toast-container') || (() => {
            const div = document.createElement('div');
            div.className = 'toast-container';
            document.body.appendChild(div);
            return div;
        })();

        const icons = { success: 'check-circle-fill', danger: 'exclamation-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
        const colors = { success: 'text-success', danger: 'text-danger', warning: 'text-warning', info: 'text-info' };

        const toast = document.createElement('div');
        toast.className = 'toast show fade-in';
        toast.innerHTML = `
            <div class="toast-body d-flex align-items-center">
                <i class="bi bi-${icons[type] || icons.info} ${colors[type] || ''} me-2 fs-5"></i>
                <span>${message}</span>
                <button type="button" class="btn-close btn-close-sm ms-auto" onclick="this.closest('.toast').remove()"></button>
            </div>
        `;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3000);
    },

    // AJAX请求
    async request(url, data = null, method = 'POST') {
        try {
            const options = {
                method,
                headers: { 'Content-Type': 'application/json' },
            };
            if (data && method !== 'GET') {
                options.body = JSON.stringify(data);
            }
            const response = await fetch(url, options);
            return await response.json();
        } catch (error) {
            console.error('Request error:', error);
            return { success: false, message: '请求失败' };
        }
    },

    // 表单AJAX提交
    async submitForm(form) {
        this.showLoading();
        try {
            const formData = new FormData(form);
            const response = await fetch(form.action || window.location.href, {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();
            this.hideLoading();
            return result;
        } catch (error) {
            this.hideLoading();
            console.error('Form submit error:', error);
            return { success: false, message: '提交失败' };
        }
    },

    // 确认对话框
    confirm(message) {
        return new Promise(resolve => {
            if (window.confirm(message)) resolve(true);
            else resolve(false);
        });
    },

    // 复制到剪贴板
    async copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.toast('已复制到剪贴板');
        } catch {
            // fallback
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
            this.toast('已复制到剪贴板');
        }
    }
};

// DOM Ready
document.addEventListener('DOMContentLoaded', function() {
    // 自动隐藏alert
    document.querySelectorAll('.alert-auto-dismiss').forEach(alert => {
        setTimeout(() => alert.remove(), 5000);
    });

    // 工具提示
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(el => new bootstrap.Tooltip(el));

    // 全局：修复modal关闭后backdrop残留（暗色遮罩问题）
    document.addEventListener('hidden.bs.modal', function(e) {
        // 移除所有残留的modal-backdrop
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        // 恢复body的滚动和样式
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    });
});
