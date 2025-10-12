(function(){
  const fmt = new Intl.DateTimeFormat(undefined, {
    year:'numeric', month:'short', day:'2-digit',
    hour:'2-digit', minute:'2-digit'
  });

  function renderTimes(root=document){
    root.querySelectorAll('time[data-epoch]').forEach(t=>{
      const s = parseInt(t.getAttribute('data-epoch'),10);
      if (!s) return;
      const d = new Date(s*1000);
      t.textContent = fmt.format(d);
      t.setAttribute('title', d.toISOString());
    });
  }

  // initial and periodic rerender
  renderTimes();
  setInterval(()=>renderTimes(), 30000);

  // global hook for dynamic content
  window.renderLocalTimes = renderTimes;
})();