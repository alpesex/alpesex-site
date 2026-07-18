const toggle=document.querySelector(".menu-toggle");
const nav=document.querySelector(".nav");
toggle.addEventListener("click",()=>{const open=nav.classList.toggle("open");toggle.setAttribute("aria-expanded",open)});
document.querySelectorAll(".nav a").forEach(a=>a.addEventListener("click",()=>nav.classList.remove("open")));

const io=new IntersectionObserver(entries=>entries.forEach(e=>{
  if(e.isIntersecting){e.target.classList.add("visible");io.unobserve(e.target)}
}),{threshold:.12});
document.querySelectorAll(".reveal").forEach(el=>io.observe(el));

const counterIO=new IntersectionObserver(entries=>entries.forEach(entry=>{
  if(!entry.isIntersecting)return;
  const el=entry.target,target=Number(el.dataset.count),suffix=el.dataset.suffix||"";
  let start=0; const duration=1200; const t0=performance.now();
  function tick(now){const p=Math.min((now-t0)/duration,1);el.textContent=Math.floor(target*(1-Math.pow(1-p,3)))+suffix;if(p<1)requestAnimationFrame(tick)}
  requestAnimationFrame(tick);counterIO.unobserve(el);
}),{threshold:.6});
document.querySelectorAll("[data-count]").forEach(el=>counterIO.observe(el));

document.getElementById("year").textContent=new Date().getFullYear();

document.getElementById("contactForm").addEventListener("submit",e=>{
  e.preventDefault();
  const f=new FormData(e.currentTarget);
  const to="alpes.ex.asm@gmail.com";
  const subject=encodeURIComponent("Demande de contact — "+(f.get("company")||f.get("name")));
  const body=encodeURIComponent(
    "Nom : "+f.get("name")+"\n"+
    "Entreprise : "+f.get("company")+"\n"+
    "E-mail : "+f.get("email")+"\n"+
    "Téléphone : "+f.get("phone")+"\n\n"+
    "Message :\n"+f.get("message")
  );
  location.href=`mailto:${to}?subject=${subject}&body=${body}`;
});
