(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root=document.getElementById('readingSessionModal');
    if(!root)return;
    const modal=new bootstrap.Modal(root),body=document.getElementById('readingSessionBody');
    let opener=null,pending=null;
    function configure(scope){
      const returns=scope.querySelector('#readingReturns');
      if(returns){
        const sync=()=>{
          const boxes=[...returns.querySelectorAll('input[name="selected[]"]')];
          const selected=boxes.filter(b=>b.checked),release=returns.querySelector('[name="release"]');
          boxes.forEach(b=>{const condition=returns.querySelector('[name="conditions['+b.value+']"]');if(condition)condition.disabled=!b.checked;});
          if(release){release.disabled=selected.length!==boxes.length;if(release.disabled)release.checked=false;}
          const problem=selected.some(b=>['Damaged','Lost'].includes(returns.querySelector('[name="conditions['+b.value+']"]').value));
          returns.querySelector('[name="note"]')?.toggleAttribute('required',problem);
          const submit=returns.querySelector('button[type="submit"]');
          if(submit){submit.disabled=!selected.length&&!release?.checked;submit.textContent=release?.checked?(selected.length?'Return selected books & give ID back':'Give ID back'):'Return selected books';}
        };
        returns.addEventListener('change',sync);sync();
      }
      const kind=scope.querySelector('#rs-kind');
      if(kind){
        const sync=()=>{
          const book=kind.value==='book';
          for(const [selector,enabled] of [['[data-correction-book]',book],['[data-correction-student]',!book]]){
            const pane=scope.querySelector(selector);pane.hidden=!enabled;
            pane.querySelectorAll('input,select').forEach(el=>{el.disabled=!enabled;if(el.tagName==='INPUT')el.required=enabled;});
          }
        };kind.addEventListener('change',sync);sync();
      }
      scope.querySelectorAll('form').forEach(form=>form.addEventListener('submit',()=>{
        form.querySelectorAll('button[type="submit"],button:not([type])').forEach(b=>{b.disabled=true;b.textContent='Saving…';});
      }));
    }
    async function open(url,trigger){
      const target=new URL(url,location.href);
      if(target.origin!==location.origin||!target.pathname.endsWith('/reading_session.php'))return;
      if(trigger)opener=trigger;
      pending?.abort();const controller=new AbortController();pending=controller;
      target.searchParams.set('fragment','1');
      body.textContent='Loading reading session…';modal.show();
      try{
        const response=await fetch(target,{credentials:'same-origin',cache:'no-store',signal:controller.signal});
        if(response.redirected||!response.ok)throw new Error('Unable to load this session. Refresh the page and sign in again if needed.');
        const html=await response.text();if(controller.signal.aborted)return;
        body.innerHTML=html;configure(body);body.scrollTop=0;
        const heading=body.querySelector('h3');if(heading){heading.tabIndex=-1;heading.focus({preventScroll:true});}
      }catch(e){if(e.name==='AbortError')return;body.textContent=e.message;}
    }
    document.addEventListener('click',event=>{
      const link=event.target.closest('a[data-reading-session],a[data-reading-nav]');
      if(!link||event.ctrlKey||event.metaKey||event.shiftKey)return;
      event.preventDefault();open(link.href,link.hasAttribute('data-reading-session')?link:null);
    });
    root.addEventListener('hidden.bs.modal',()=>{pending?.abort();opener?.focus();});
    configure(document);
    const query=new URLSearchParams(location.search),key=query.get('reading_session');
    if(key){
      const params=new URLSearchParams({key,return_to:location.pathname.endsWith('/desk_form.php')?'desk_form.php':'in_library_use.php',view:query.get('reading_view')||'books',book:query.get('reading_book')||'0',return_query:new URLSearchParams([...query].filter(([k])=>['q','book','type','page','tab'].includes(k))).toString()});
      open('reading_session.php?'+params);
      ['reading_session','reading_view','reading_book'].forEach(k=>query.delete(k));
      history.replaceState(null,'',location.pathname+(query.toString()?'?'+query:'')+location.hash);
    }
  });
})();
