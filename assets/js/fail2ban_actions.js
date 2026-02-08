document.addEventListener('DOMContentLoaded', function(){
  async function jsonPost(url, data){
    const f = await fetch(url, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(data)});
    const t = await f.json().catch(()=>({ok:false,error:'invalid-json'}));
    return t;
  }

  // handle the ban/unban form in settings
  var form = document.querySelector('form[method="post"][name="fail2ban_form"]');
  if (form){
    form.addEventListener('submit', async function(ev){
      ev.preventDefault();
      var jail = form.querySelector('select[name=jail]').value;
      var ip = form.querySelector('input[name=ip]').value.trim();
      var btn = document.activeElement;
      var act = btn ? btn.getAttribute('data-act') : 'ban';
      if (!jail){ alert('Select a jail'); return; }
      if (!ip){ alert('Enter an IP'); return; }
      try{
        var res = await jsonPost('/ajax/fail2ban.php', {jail:jail, act:act, ip:ip});
        if (!res.ok){ alert('Error: '+(res.error||res.out||JSON.stringify(res))); return; }
        alert('Action executed. '+(res.out||'') );
      }catch(e){ alert('Request failed: '+e); }
    });
  }

  // attach refresh buttons
  window.refetchJail = async function(jail){
    var el = document.getElementById('fail-jail-'+jail);
    if (!el) {
      // try open modal instead
      return fetch('/ajax/fail2ban.php?jail='+encodeURIComponent(jail)).then(r=>r.json()).then(j=>{ if (j.ips && j.ips.length) alert('Banned: '+j.ips.join(', ')); else alert('No banned IPs. Raw: '+(j.raw||'')); });
    }
  }
});
