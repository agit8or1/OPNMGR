document.addEventListener('DOMContentLoaded', function(){
  window.fetchJail = function(btn){
    var jail = btn.getAttribute('data-jail');
    var modalEl = document.getElementById('failModal');
    var body = document.getElementById('failModalBody');
    body.textContent = 'Loading...';
    var modal = new bootstrap.Modal(modalEl);
    fetch('/ajax/fail2ban.php?jail='+encodeURIComponent(jail)).then(function(r){ if(!r.ok) return r.text().then(t=>{throw t}); return r.text(); }).then(function(t){ body.textContent = t }).catch(function(e){ body.textContent = 'Error: '+e; });
    modal.show();
  }
});
