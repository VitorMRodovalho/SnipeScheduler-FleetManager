document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('adminDropdownBtn');
    var menu = document.getElementById('adminDropdownMenu');
    if (!btn || !menu) return;
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var isOpen = menu.classList.contains('show');
        if (isOpen) {
            menu.classList.remove('show');
            menu.style.display = 'none';
            document.body.removeChild(menu);
            btn.parentElement.appendChild(menu);
            return;
        }
        var rect = btn.getBoundingClientRect();
        document.body.appendChild(menu);
        menu.style.position = 'fixed';
        menu.style.top = (rect.bottom + 2) + 'px';
        menu.style.left = 'auto';
        menu.style.right = (window.innerWidth - rect.right) + 'px';
        menu.style.display = 'block';
        menu.classList.add('show');
        function closeMenu(ev) {
            if (!menu.contains(ev.target) && ev.target !== btn) {
                menu.classList.remove('show');
                menu.style.display = 'none';
                try { document.body.removeChild(menu); } catch(e){}
                btn.parentElement.appendChild(menu);
                document.removeEventListener('click', closeMenu);
            }
        }
        setTimeout(function(){ document.addEventListener('click', closeMenu); }, 10);
    });
});
