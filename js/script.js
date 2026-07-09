const menuToggle = document.querySelector(".menu-toggle");
const nav = document.querySelector(".main-nav");
menuToggle?.addEventListener("click", () => nav.classList.toggle("open"));

const reveals = document.querySelectorAll(".reveal");
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if(entry.isIntersecting){
      entry.target.classList.add("visible");
      observer.unobserve(entry.target);
    }
  });
},{threshold:.14});
reveals.forEach(el => observer.observe(el));

const counters = document.querySelectorAll("[data-count]");
const counterObserver = new IntersectionObserver((entries)=>{
  entries.forEach(entry=>{
    if(!entry.isIntersecting) return;
    const el = entry.target;
    const target = Number(el.dataset.count);
    let current = 0;
    const step = Math.max(1, Math.ceil(target/48));
    const timer = setInterval(()=>{
      current += step;
      if(current >= target){
        el.textContent = target;
        clearInterval(timer);
      } else {
        el.textContent = current;
      }
    }, 28);
    counterObserver.unobserve(el);
  });
},{threshold:.6});
counters.forEach(el=>counterObserver.observe(el));
