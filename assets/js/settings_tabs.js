document.addEventListener('DOMContentLoaded', function(){
  const nav = document.getElementById('settings-nav');
  if(!nav) return;
  const buttons = nav.querySelectorAll('button[data-target]');
  function show(target){
    document.querySelectorAll('.settings-section').forEach(s=>{
      if(s.id===target) s.classList.remove('d-none'); else s.classList.add('d-none');
    });
    buttons.forEach(b=> b.classList.toggle('active', b.dataset.target===target));
  }
  buttons.forEach(b=> b.addEventListener('click', function(){ show(this.dataset.target); }));
  // show first active or branding
  const active = nav.querySelector('button.active') || nav.querySelector('button[data-target]');
  if(active) show(active.dataset.target);
});
