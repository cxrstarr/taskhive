(function(){
  const MAX_FILES = 5;
  const MAX_TOTAL_MB = 25;
  const input = document.getElementById('attachInput');
  const bar = document.getElementById('previewBar');
  const meta = document.getElementById('previewMeta');

  function bytesToMB(b){ return (b/1024/1024).toFixed(2); }

  function refreshMeta(files){
    if (!meta) return;
    const total = Array.from(files).reduce((s,f)=>s+f.size,0);
    meta.textContent = files.length
      ? files.length + ' image(s) selected • ' + bytesToMB(total) + ' MB total'
      : '';
  }

  function render(files){
    if (!bar) return;
    bar.innerHTML = '';
    Array.from(files).forEach((file, idx)=>{
      const wrap = document.createElement('div');
      wrap.style.cssText = 'position:relative;width:84px;height:84px;border-radius:8px;overflow:hidden;border:1px solid #e3e6ed;background:#fff;';
      const img = document.createElement('img');
      img.style.cssText='width:100%;height:100%;object-fit:cover;display:block;';
      const rm = document.createElement('button');
      rm.type='button';
      rm.textContent='×';
      rm.title='Remove';
      rm.style.cssText='position:absolute;top:4px;right:4px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:999px;width:22px;height:22px;line-height:22px;text-align:center;font-size:12px;cursor:pointer;';
      rm.addEventListener('click', ()=>{
        const dt = new DataTransfer();
        Array.from(files).forEach((f,i)=>{ if(i!==idx) dt.items.add(f); });
        input.files = dt.files;
        render(input.files);
      });
      wrap.appendChild(img);
      wrap.appendChild(rm);
      bar.appendChild(wrap);
      const reader = new FileReader();
      reader.onload = e => { img.src = e.target.result; };
      reader.readAsDataURL(file);
    });
    refreshMeta(files);
  }

  if (input) {
    input.addEventListener('change', ()=>{
      let files = Array.from(input.files);
      if (files.length > MAX_FILES) files = files.slice(0, MAX_FILES);

      const toBytes = (mb)=>mb*1024*1024;
      let totalBytes = files.reduce((s,f)=>s+f.size,0);
      if (totalBytes > toBytes(MAX_TOTAL_MB)) {
        // keep smallest first until under cap
        files.sort((a,b)=>a.size-b.size);
        const kept = [];
        let sum = 0;
        for (const f of files) {
          if (sum + f.size <= toBytes(MAX_TOTAL_MB)) { kept.push(f); sum+=f.size; } else break;
        }
        files = kept;
      }

      // write back trimmed selection
      const dt = new DataTransfer();
      files.forEach(f=>dt.items.add(f));
      input.files = dt.files;
      render(input.files);
    });
  }
})();