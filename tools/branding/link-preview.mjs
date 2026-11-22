const roles=Object.freeze({
 admin:{name:'OG Yönetici Portalı',host:'yonetim.omnigoglobal.com',description:'Pazaryeri ve operasyon yönetimi'},
 client:{name:'OG İş Ortağım',host:'portal.omnigoglobal.com',description:'İşletmeniz için tek çalışma alanı'},
});
export function linkPreview(role){
 if(!Object.hasOwn(roles,role))throw new Error('Bağlantı önizleme rolü geçersiz.');
 return {...roles[role],image:`assets/link-${role}-${role==='client'?'v3':'v2'}.png`,width:1200,height:630};
}
export function previewMetadata(role){
 const data=linkPreview(role),origin='https://'+data.host;
 return `<meta property="og:type" content="website"><meta property="og:site_name" content="Omnigo Global"><meta property="og:title" content="${data.name}"><meta property="og:description" content="${data.description}"><meta property="og:url" content="${origin}/"><meta property="og:image" content="${origin}/${data.image}"><meta property="og:image:secure_url" content="${origin}/${data.image}"><meta property="og:image:type" content="image/png"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="${data.name}"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="${data.name}"><meta name="twitter:image" content="${origin}/${data.image}">`;
}
export function withPreviewMetadata(html,role){
 const clean=html.replace(/<meta\b[^>]*(?:property="og:[^"]*"|name="twitter:[^"]*")[^>]*>/g,'');
 if(!clean.includes('</head>'))throw new Error('Bağlantı önizlemesi için sayfa başlığı bulunamadı.');
 return clean.replace('</head>',previewMetadata(role)+'</head>');
}
export function previewCard(role,icon){
 const data=linkPreview(role);
 return `<!doctype html><html lang="tr"><meta charset="utf-8"><style>*{box-sizing:border-box}html,body{margin:0;width:1200px;height:630px}body{font-family:"Segoe UI",Arial,sans-serif;background:linear-gradient(125deg,#101c2c,#19293b);color:#eef1f6;display:flex;align-items:center;padding:72px;gap:58px}body::before{content:"";position:absolute;inset:28px;border:1px solid #c7aa7240;border-radius:20px}img{width:280px;height:280px;object-fit:contain;flex:none}main{position:relative;flex:1;min-width:0}small{color:#c7aa72;font-size:21px;letter-spacing:4px;font-weight:600}h1{font-size:54px;line-height:1.2;font-weight:600;margin:24px 0 18px;letter-spacing:-1px}p{color:#b9c6d6;font-size:28px;margin:0;line-height:1.5}.domain{margin-top:38px;border-top:1px solid #c7aa7240;padding-top:24px;color:#dfc994;font-size:25px;letter-spacing:.4px}</style><img src="${icon}" alt=""><main><small>OMNIGO GLOBAL</small><h1>${data.name}</h1><p>${data.description}</p><div class="domain">${data.host}</div></main></html>`;
}
