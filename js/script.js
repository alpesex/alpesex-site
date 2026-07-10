const toggle=document.querySelector('.menu-toggle');const nav=document.querySelector('.main-nav');toggle?.addEventListener('click',()=>nav.classList.toggle('open'));document.querySelectorAll('.main-nav a').forEach(a=>a.addEventListener('click',()=>nav.classList.remove('open')));
const observer=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('visible');observer.unobserve(e.target)}}),{threshold:.12});document.querySelectorAll('.reveal').forEach(el=>observer.observe(el));
const counterObserver=new IntersectionObserver(entries=>entries.forEach(e=>{if(!e.isIntersecting)return;const el=e.target,target=+el.dataset.count;let n=0;const step=Math.max(1,Math.ceil(target/45));const timer=setInterval(()=>{n+=step;if(n>=target){n=target;clearInterval(timer)}el.textContent=n},30);counterObserver.unobserve(el)}),{threshold:.6});document.querySelectorAll('[data-count]').forEach(el=>counterObserver.observe(el));
const equipmentSlider=document.querySelector('#equipmentSlider');
const prevBtn=document.querySelector('.slider-btn.prev');
const nextBtn=document.querySelector('.slider-btn.next');
const slideBy=()=>Math.max(320,equipmentSlider?.clientWidth*.72||320);
prevBtn?.addEventListener('click',()=>equipmentSlider.scrollBy({left:-slideBy(),behavior:'smooth'}));
nextBtn?.addEventListener('click',()=>equipmentSlider.scrollBy({left:slideBy(),behavior:'smooth'}));

let equipmentTimer;
const startEquipmentAuto=()=>{
  if(!equipmentSlider || window.matchMedia('(prefers-reduced-motion: reduce)').matches)return;
  equipmentTimer=setInterval(()=>{
    const end=equipmentSlider.scrollLeft+equipmentSlider.clientWidth>=equipmentSlider.scrollWidth-12;
    equipmentSlider.scrollTo({left:end?0:equipmentSlider.scrollLeft+slideBy(),behavior:'smooth'});
  },5200);
};
const stopEquipmentAuto=()=>clearInterval(equipmentTimer);
equipmentSlider?.addEventListener('mouseenter',stopEquipmentAuto);
equipmentSlider?.addEventListener('mouseleave',startEquipmentAuto);
equipmentSlider?.addEventListener('touchstart',stopEquipmentAuto,{passive:true});
startEquipmentAuto();

const header=document.querySelector('.site-header');
window.addEventListener('scroll',()=>header?.classList.toggle('compact',window.scrollY>70),{passive:true});
