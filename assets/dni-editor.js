(function(){
  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost || {};
  const { PanelBody, Button, Notice } = wp.components;
  const { useState } = wp.element;
  const { select } = wp.data;
  const apiFetch = wp.apiFetch;

  function Panel(){
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');
    const [suggest, setSuggest] = useState(null);
    const postId = (select('core/editor') && select('core/editor').getCurrentPostId()) || (select('core') && select('core').getCurrentPostId && select('core').getCurrentPostId());

    async function insertConcludingParagraph(){
      setBusy(true); setMsg('');
      try{
        const url = DNI.restBase + 'posts/' + postId + '/blocks';
        const res = await fetch(url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': DNI.nonce
          },
          body: JSON.stringify({
            insert: 'append',
            block: { type:'core/paragraph', content: 'In conclusion, adopting a clean machine representation unlocks faster, safer, and more reliable AI authoring inside WordPress.' }
          })
        });
        if(!res.ok){
          const t = await res.text();
          throw new Error('HTTP '+res.status+': '+t);
        }
        setMsg('Paragraph inserted at end of post.');
      }catch(e){ setMsg('Error: '+e.message); }
      setBusy(false);
    }

    async function suggestAI(){
      setBusy(true); setMsg(''); setSuggest(null);
      try{
        const url = DNI.restBase + 'posts/' + postId + '/ai/suggest';
        const res = await fetch(url, { headers: { 'X-WP-Nonce': DNI.nonce } });
        if(!res.ok){ throw new Error('HTTP '+res.status); }
        const data = await res.json();
        setSuggest(data);
        setMsg('Suggestions generated.');
      }catch(e){ setMsg('Error: '+e.message); }
      setBusy(false);
    }

    async function insertAtCursorHeading(){
      setBusy(true); setMsg('');
      try{
        const be = (wp.data.select('core/block-editor') || wp.data.select('core/editor'));
        let clientId = be && be.getSelectedBlockClientId ? be.getSelectedBlockClientId() : null;
        // Walk up to top-level block for a stable top-level index
        if (be && be.getBlockRootClientId && clientId){
          let parent = be.getBlockRootClientId(clientId);
          while (parent) { clientId = parent; parent = be.getBlockRootClientId(clientId); }
        }
        let idx = 0;
        if (clientId && be && be.getBlockIndex){
          idx = be.getBlockIndex(clientId, null) || 0; // top-level index
        } else if (be && be.getBlockCount){
          idx = be.getBlockCount(null) || 0; // append at end if no selection
        }

        const url = DNI.restBase + 'posts/' + postId + '/blocks';
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'Content-Type':'application/json', 'X-WP-Nonce': DNI.nonce },
          body: JSON.stringify({
            insert: 'index',
            index: idx,
            blocks: [ { type:'core/heading', level:2, content: 'Key Takeaways' } ]
          })
        });
        if(!res.ok){
          const t = await res.text();
          throw new Error('HTTP '+res.status+': '+t);
        }
        setMsg('Inserted H2 at current cursor position.');
      }catch(e){ setMsg('Error: '+e.message); }
      setBusy(false);
    }

    async function applySummary(){
      if(!suggest || !suggest.summary) return;
      setBusy(true); setMsg('');
      try{
        await wp.apiFetch({ path: '/wp/v2/posts/'+postId, method:'POST', data: { excerpt: suggest.summary } });
        setMsg('Summary applied to excerpt.');
      }catch(e){ setMsg('Error applying: '+(e.message||e) ); }
      setBusy(false);
    }

    async function viewMr(){
      setBusy(true); setMsg('');
      try{
        const url = DNI.restBase + 'posts/' + postId;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': DNI.nonce } });
        const data = await res.json();
        console.log('MR', data);
        setMsg('Fetched MR (see console). CID: '+(data && data.cid));
      }catch(e){ setMsg('Error: '+e.message); }
      setBusy(false);
    }

    async function copyMrJson(){
      setBusy(true); setMsg('');
      try{
        const url = DNI.restBase + 'posts/' + postId;
        const res = await fetch(url, { headers: { 'X-WP-Nonce': DNI.nonce } });
        if(!res.ok){ throw new Error('HTTP '+res.status); }
        const data = await res.json();
        const text = JSON.stringify(data, null, 2);
        if (navigator.clipboard && navigator.clipboard.writeText){
          await navigator.clipboard.writeText(text);
        } else {
          const ta = document.createElement('textarea');
          ta.value = text;
          ta.setAttribute('readonly','');
          ta.style.position = 'absolute';
          ta.style.left = '-9999px';
          document.body.appendChild(ta);
          ta.select();
          document.execCommand('copy');
          document.body.removeChild(ta);
        }
        setMsg('Copied MR JSON to clipboard.');
      }catch(e){ setMsg('Error: '+e.message); }
      setBusy(false);
    }

    function openMarkdownMr(){
      try{
        const url = DNI.restBase + 'posts/' + postId + '/md?_wpnonce=' + encodeURIComponent(DNI.nonce);
        const w = window.open(url, '_blank');
        if(!w){ setMsg('Popup blocked. Enable popups to open Markdown MR.'); }
      }catch(e){ setMsg('Error: '+e.message); }
    }

    return wp.element.createElement(PanelBody, { title: 'Dual‑Native AI', initialOpen: true },
      msg ? wp.element.createElement(Notice, { status: 'info', isDismissible: true, onRemove: ()=>setMsg('') }, msg) : null,
      wp.element.createElement(Button, { isPrimary:true, disabled:busy, onClick: insertConcludingParagraph }, busy?'Working…':'Insert concluding paragraph'),
      wp.element.createElement('div', { style:{height:8} }),
      wp.element.createElement(Button, { isSecondary:true, disabled:busy, onClick: insertAtCursorHeading }, 'Insert at cursor (H2)'),
      wp.element.createElement('div', { style:{height:8} }),
      wp.element.createElement(Button, { isSecondary:true, disabled:busy, onClick: viewMr }, 'Preview MR in console'),
      wp.element.createElement('div', { style:{height:8} }),
      wp.element.createElement(Button, { isSecondary:true, disabled:busy, onClick: copyMrJson }, 'Copy MR JSON'),
      wp.element.createElement('div', { style:{height:8} }),
      wp.element.createElement(Button, { isSecondary:true, disabled:busy, onClick: openMarkdownMr }, 'Open Markdown MR'),
      wp.element.createElement('div', { style:{height:8} }),
      wp.element.createElement(Button, { isSecondary:true, disabled:busy, onClick: suggestAI }, busy?'Working…':'Suggest summary & tags'),
      suggest ? wp.element.createElement('div', { style:{marginTop:8} },
        wp.element.createElement('div', { className:'suggest-summary', style:{marginBottom:6} },
          wp.element.createElement('strong', null, 'Suggested Summary:'),
          wp.element.createElement('div', null, suggest.summary || '(none)')
        ),
        wp.element.createElement('div', null,
          wp.element.createElement('strong', null, 'Suggested Tags:'), ' ',
          (suggest.tags||[]).join(', ')
        ),
        wp.element.createElement('div', { style:{marginTop:6} },
          wp.element.createElement(Button, { isSmall:true, onClick: applySummary, disabled:busy }, 'Apply summary to excerpt')
        )
      ) : null
    );
  }

  if (PluginSidebar){
    registerPlugin('dual-native-ai', {
      render: function(){
        return [
          wp.element.createElement(PluginSidebarMoreMenuItem, { target: 'dual-native-ai', icon: 'analytics' }, 'Dual‑Native AI'),
          wp.element.createElement(PluginSidebar, { name:'dual-native-ai', title:'Dual‑Native AI' }, wp.element.createElement(Panel))
        ];
      }
    });
  }
})();
