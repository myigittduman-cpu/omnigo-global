export const colorFields = {
 background: ['Sayfa arka planı', '#141b28', ['--navy','--settings-deep','--light-bg']],
 surface: ['Panel ve pencere zemini', '#202735', ['--settings-surface','--light-surface']],
 text: ['Ana yazı', '#edf2f7', ['--light-ink']],
 muted: ['İkincil yazı', '#a9b6c7', ['--muted','--light-muted']],
 accent: ['Vurgu ve başlık', '#c7aa72', ['--gold','--settings-accent','--light-accent']],
 border: ['Ayırıcı ve kenarlık', '#394456', ['--line','--control-border','--settings-border','--light-line']],
};

export function applyPalette(palette={},root=document.documentElement){
 for(const [key,[,,aliases]] of Object.entries(colorFields)){
  const color=palette[key];
  const enabled=/^#[0-9a-f]{6}$/i.test(color);
  if(root.dataset['palette'+key] === undefined && !enabled)continue;
  for(const variable of ['--workspace-'+key,...aliases]){
   if(enabled)root.style.setProperty(variable,color);
   else root.style.removeProperty(variable);
  }
  if(enabled)root.dataset['palette'+key]='';
  else delete root.dataset['palette'+key];
 }
}
