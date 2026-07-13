import{u as z}from"./in__RfOp.js";import{x as P,r as m}from"./w2xcD0hj.js";const g=m(!1),v=m([]),l=m("");typeof window<"u"&&(l.value=localStorage.getItem("selected_printer")||"");const d=c=>String(c??"").replace(/[&<>"']/g,f=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[f]),A=()=>{const c=z(),f=async()=>{typeof window<"u"&&window.electronAPI?(g.value=!0,await h()):(console.warn("Electron API not found. Printing will use browser default."),g.value=!1)},h=async()=>{if(typeof window<"u"&&window.electronAPI)try{const t=await window.electronAPI.getPrinters();if(v.value=t.map(e=>e.name),!l.value){const e=t.find(i=>i.isDefault);e&&x(e.name)}}catch(t){console.error("Failed to fetch printers:",t)}},x=t=>{l.value=t,localStorage.setItem("selected_printer",t)},y=(t,e={})=>{const i=t?.items?.map((r,$)=>`
      <tr style="border-bottom: 1px solid #f1f5f9;">
        <td style="padding: 10px 8px; color: #64748b; font-size: 11px;">${$+1}</td>
        <td style="padding: 10px 8px; font-weight: 500; color: #1e293b; font-size: 12px;">${d(r.product_name||r.name)}</td>
        <td style="padding: 10px 8px; text-align: center; color: #64748b; font-size: 12px;">${r.quantity} шт.</td>
        <td style="padding: 10px 8px; text-align: right; color: #64748b; font-size: 12px;">${Math.round(r.price).toLocaleString()}</td>
        <td style="padding: 10px 8px; text-align: right; font-weight: 600; color: #0f172a; font-size: 12px;">${Math.round(r.price*r.quantity).toLocaleString()}</td>
      </tr>
    `).join("")||'<tr><td colspan="5" style="text-align:center; padding: 20px;">Нет данных</td></tr>',n=d(e.receipt_title||e.site_name||"BRAND STORE"),o=new Date(t?.created_at||new Date).toLocaleString("ru-RU",{day:"2-digit",month:"long",year:"numeric",hour:"2-digit",minute:"2-digit"}),a=d(t?.order_number||t?.id||"_______"),s=Number(t?.total_amount||t?.total||0),p=Number(t?.discount||0),w=Number(t?.total||s);return`
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="utf-8">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
          body { font-family: 'Inter', sans-serif; color: #1e293b; margin: 0; padding: 30px; background: white; line-height: 1.5; }
          .invoice-box { max-width: 800px; margin: auto; }
          .header { display: flex; justify-content: space-between; border-bottom: 2px solid #f1f5f9; padding-bottom: 20px; margin-bottom: 30px; }
          .brand { color: #0ea5e9; font-size: 22px; font-weight: 800; }
          .meta { text-align: right; font-size: 11px; color: #64748b; }
          .meta strong { color: #1e293b; }
          .doc-title { font-size: 22px; font-weight: 800; margin: 20px 0; color: #0f172a; }
          .badges { display: flex; gap: 8px; margin-bottom: 30px; }
          .badge { padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
          .badge-blue { background: #f0f9ff; color: #0369a1; border: 1px solid #e0f2fe; }
          .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-bottom: 30px; font-size: 12px; }
          .info-label { color: #94a3b8; text-transform: uppercase; font-size: 9px; font-weight: 700; margin-bottom: 6px; letter-spacing: 1px; }
          table { width: 100%; border-collapse: collapse; }
          th { text-align: left; background: #f8fafc; padding: 10px 8px; color: #64748b; font-size: 9px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; }
          .total-box { margin-top: 30px; margin-left: auto; width: 220px; border-top: 1.5px solid #f1f5f9; padding-top: 15px; }
          .total-row { display: flex; justify-content: space-between; margin-bottom: 6px; font-size: 11px; color: #64748b; }
          .grand-total { margin-top: 10px; padding-top: 10px; font-size: 15px; font-weight: 800; color: #0ea5e9; }
          .signatures { margin-top: 60px; display: flex; justify-content: space-between; font-size: 11px; color: #64748b; }
          .sig-line { width: 120px; border-bottom: 1px solid #e2e8f0; display: inline-block; margin: 0 8px; }
        </style>
      </head>
      <body>
        <div class="invoice-box">
          <div class="header">
            <div class="brand">${n}</div>
            <div class="meta">
              <div>Электронный документ: <strong>#${a}</strong></div>
              <div>Дата: <strong>${o}</strong></div>
            </div>
          </div>

          <div class="doc-title">Расходная накладная</div>
          
          <div class="badges">
            <div class="badge badge-blue">Оплачено</div>
            <div class="badge badge-blue">Электронная копия</div>
          </div>

          <div class="info-grid">
            <div>
              <div class="info-label">Поставщик</div>
              <div style="font-weight: 600;">${n}</div>
              <div style="color: #64748b;">Розничная торговля</div>
            </div>
            <div>
              <div class="info-label">Покупатель</div>
              <div style="font-weight: 600;">${d(t?.user?.name||"Розничный покупатель")}</div>
              ${t?.user?.id&&!String(t.user.id).includes("retail")?`<div style="color: #64748b;">ID: ${d(t.user.id)}</div>`:""}
            </div>
          </div>

          <table>
            <thead>
              <tr>
                <th style="width: 40px;">№</th>
                <th>Товар</th>
                <th style="text-align: center; width: 80px;">Кол-во</th>
                <th style="text-align: right; width: 100px;">Цена</th>
                <th style="text-align: right; width: 120px;">Сумма</th>
              </tr>
            </thead>
            <tbody>${i}</tbody>
          </table>

          <div class="total-box">
            <div class="total-row">
              <span>Подытог:</span>
              <span>${Math.round(s).toLocaleString()}</span>
            </div>
            ${p>0?`<div class="total-row" style="color: #ef4444;"><span>Скидка:</span><span>-${p.toLocaleString()}</span></div>`:""}
            <div class="total-row grand-total">
              <span>ИТОГО:</span>
              <span>${Math.round(w).toLocaleString()} сом</span>
            </div>
          </div>

          <div class="signatures">
            <div>Отпустил (подпись): <span class="sig-line"></span></div>
            <div>Получил (подпись): <span class="sig-line"></span></div>
          </div>
        </div>
      </body>
      </html>
    `},u=(t,e={})=>{const i=t.items.map(s=>`
      <tr>
        <td style="padding: 1mm 0; font-size: 10px;">${d(s.product_name||s.name)}</td>
        <td style="padding: 1mm 0; font-size: 10px; text-align: center;">${s.quantity}</td>
        <td style="padding: 1mm 0; font-size: 10px; text-align: right;">${Math.round(s.price)}</td>
        <td style="padding: 1mm 0; font-size: 10px; text-align: right;">${Math.round(s.price*s.quantity)}</td>
      </tr>
    `).join(""),n=d(e.receipt_title||e.site_name||"МОЙ МАГАЗИН"),o=new Date().toLocaleString("ru-RU"),a=d(t.order_number||"OFF-LINE");return`
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="utf-8">
        <style>
          @page { size: 80mm auto; margin: 0; }
          body { font-family: sans-serif; width: 72mm; padding: 5mm; font-size: 12px; line-height: 1.2; }
          .center { text-align: center; }
          .bold { font-weight: bold; }
          .divider { border-top: 1px dashed black; margin: 2mm 0; }
          table { width: 100%; border-collapse: collapse; }
          th { text-align: left; border-bottom: 1px solid black; font-size: 10px; }
        </style>
      </head>
      <body>
        <div class="center">
          <div style="font-size: 16px; font-weight: bold;">${n}</div>
          <div style="font-size: 10px; margin-top: 1mm;">${o}</div>
          <div style="font-size: 10px;">ЧЕК №: ${a}</div>
        </div>
        <div class="divider"></div>
        <table>
          <thead>
            <tr><th>Товар</th><th>Кол</th><th>Цена</th><th style="text-align: right;">Сумма</th></tr>
          </thead>
          <tbody>${i}</tbody>
        </table>
        <div class="divider"></div>
        <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 14px;">
          <span>ИТОГО:</span>
          <span>${Math.round(t.total||t.total_amount||0)} сом</span>
        </div>
        ${t.discount>0?`<div style="text-align: right; font-size: 10px;">Скидка: -${t.discount}</div>`:""}
        <div class="divider"></div>
        <div class="center" style="font-size: 10px; font-style: italic; margin-top: 5mm;">
          СПАСИБО ЗА ПОКУПКУ!<br>Товар обмену и возврату подлежит<br>в течение 14 дней при наличии чека
        </div>
        <div style="margin-top: 5mm; border-bottom: 2px solid black;"></div>
      </body>
      </html>
    `},b=t=>{const e=document.createElement("iframe");e.style.position="fixed",e.style.width="0",e.style.height="0",e.style.border="0",e.style.visibility="hidden",document.body.appendChild(e);let i=!1;const n=()=>{i||(i=!0,e.parentNode&&document.body.removeChild(e))},o=e.contentWindow,a=o?.document;if(!o||!a){n();return}a.open(),a.write(t),a.close(),setTimeout(()=>{o.onafterprint=n,o.focus(),o.print(),setTimeout(n,1e4)},300)};return{isConnected:g,printers:v,activePrinter:l,initPrinter:f,fetchPrinters:h,setPrinter:x,printReceipt:async(t,e="thermal")=>{try{let i="";if(typeof t=="object"&&t!==null&&(!t.items||!Array.isArray(t.items))){console.warn("Order data has no items, falling back to server-side printing");const n=t.uuid||t.id;n&&(t=n)}if(typeof t=="object"&&t!==null)i=u(t);else{const{getAuthToken:n,baseURL:o}=P(),a=n(),s=e==="thermal"?`/reports/order/${t}/thermal/html`:`/reports/order/${t}/html`,p=await fetch(`${o}${s}`,{headers:{Authorization:`Bearer ${a}`}});if(!p.ok)throw new Error("Ошибка сервера при получении чека");i=await p.text()}typeof window<"u"&&window.electronAPI?window.electronAPI.printHTML({html:i,printerName:l.value}):b(i)}catch(i){console.error("Printing failing:",i),c.addToast("Ошибка печати: "+i.message,"error")}},testPrint:async()=>{const t=`
      <div style="font-family: sans-serif; padding: 20px; border: 2px solid black; text-align: center;">
        <h2 style="margin: 0;">ТЕСТ ПЕЧАТИ</h2>
        <p>Магазин: ${typeof window<"u"?window.location.hostname:"Local"}</p>
        <p>Принтер: ${l.value||"По умолчанию"}</p>
        <hr>
        <p>Если вы видите этот текст, значит система настроена верно!</p>
        <p style="font-size: 12px; color: gray;">Дата: ${new Date().toLocaleString()}</p>
      </div>
    `;typeof window<"u"&&window.electronAPI?(window.electronAPI.printHTML({html:t,printerName:l.value}),c.addToast("Тестовая страница отправлена","success")):b(t)},generateReceiptHtml:u,generateInvoiceHtml:y}};export{A as u};
