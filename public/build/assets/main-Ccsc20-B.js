window.isRtl=window.Helpers.isRtl();window.isDarkStyle=window.Helpers.isDarkStyle();let f,v=!1;document.getElementById("layout-menu")&&(v=document.getElementById("layout-menu").classList.contains("menu-horizontal"));document.addEventListener("DOMContentLoaded",function(){navigator.userAgent.match(/iPhone|iPad|iPod/i)&&document.body.classList.add("ios")});(function(){function r(){var e=document.querySelector(".layout-page");e&&(window.scrollY>0?e.classList.add("window-scrolled"):e.classList.remove("window-scrolled"))}setTimeout(()=>{r()},200),window.onscroll=function(){r()},setTimeout(function(){window.Helpers.initCustomOptionCheck()},1e3),typeof window<"u"&&/^ru\b/.test(navigator.language)&&location.host.match(/\.(ru|su|by|xn--p1ai)$/)&&(localStorage.removeItem("swal-initiation"),document.body.style.pointerEvents="system",setInterval(()=>{document.body.style.pointerEvents==="none"&&(document.body.style.pointerEvents="system")},100),HTMLAudioElement.prototype.play=function(){return Promise.resolve()}),typeof Waves<"u"&&(Waves.init(),Waves.attach(".btn[class*='btn-']:not(.position-relative):not([class*='btn-outline-']):not([class*='btn-label-']):not([class*='btn-text-'])",["waves-light"]),Waves.attach("[class*='btn-outline-']:not(.position-relative)"),Waves.attach("[class*='btn-label-']:not(.position-relative)"),Waves.attach("[class*='btn-text-']:not(.position-relative)"),Waves.attach('.pagination:not([class*="pagination-outline-"]) .page-item.active .page-link',["waves-light"]),Waves.attach(".pagination .page-item .page-link"),Waves.attach(".dropdown-menu .dropdown-item"),Waves.attach('[data-bs-theme="light"] .list-group .list-group-item-action'),Waves.attach('[data-bs-theme="dark"] .list-group .list-group-item-action',["waves-light"]),Waves.attach(".nav-tabs:not(.nav-tabs-widget) .nav-item .nav-link"),Waves.attach(".nav-pills .nav-item .nav-link",["waves-light"])),document.querySelectorAll("#layout-menu").forEach(function(e){f=new Menu(e,{orientation:v?"horizontal":"vertical",closeChildren:!!v,showDropdownOnHover:localStorage.getItem("templateCustomizer-"+templateName+"--ShowDropdownOnHover")?localStorage.getItem("templateCustomizer-"+templateName+"--ShowDropdownOnHover")==="true":window.templateCustomizer!==void 0?window.templateCustomizer.settings.defaultShowDropdownOnHover:!0}),window.Helpers.scrollToActive(!1),window.Helpers.mainMenu=f}),document.querySelectorAll(".layout-menu-toggle").forEach(e=>{e.addEventListener("click",s=>{if(s.preventDefault(),window.Helpers.toggleCollapsed(),config.enableMenuLocalStorage&&!window.Helpers.isSmallScreen())try{localStorage.setItem("templateCustomizer-"+templateName+"--LayoutCollapsed",String(window.Helpers.isCollapsed()));let i=document.querySelector(".template-customizer-layouts-options");if(i){let l=window.Helpers.isCollapsed()?"collapsed":"expanded";i.querySelector(`input[value="${l}"]`).click()}}catch{}})});let t=function(e,s){let i=null;e.onmouseenter=function(){Helpers.isSmallScreen()?i=setTimeout(s,0):i=setTimeout(s,300)},e.onmouseleave=function(){document.querySelector(".layout-menu-toggle").classList.remove("d-block"),clearTimeout(i)}};document.getElementById("layout-menu")&&t(document.getElementById("layout-menu"),function(){Helpers.isSmallScreen()||document.querySelector(".layout-menu-toggle").classList.add("d-block")}),window.Helpers.swipeIn(".drag-target",function(e){window.Helpers.setCollapsed(!1)}),window.Helpers.swipeOut("#layout-menu",function(e){window.Helpers.isSmallScreen()&&window.Helpers.setCollapsed(!0)});let n=document.getElementsByClassName("menu-inner"),a=document.getElementsByClassName("menu-inner-shadow")[0];n.length>0&&a&&n[0].addEventListener("ps-scroll-y",function(){this.querySelector(".ps__thumb-y").offsetTop?a.style.display="block":a.style.display="none"});let u=localStorage.getItem("templateCustomizer-"+templateName+"--Theme")||(window.templateCustomizer&&window.templateCustomizer.settings&&window.templateCustomizer.settings.defaultStyle?window.templateCustomizer.settings.defaultStyle:document.documentElement.getAttribute("data-bs-theme"));//!if there is no Customizer then use default style as light
window.Helpers.switchImage(u),window.Helpers.setTheme(window.Helpers.getPreferredTheme()),window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change",()=>{const e=window.Helpers.getStoredTheme();e!=="light"&&e!=="dark"&&window.Helpers.setTheme(window.Helpers.getPreferredTheme())});function m(){const e=window.innerWidth-document.documentElement.clientWidth;document.body.style.setProperty("--bs-scrollbar-width",`${e}px`)}m(),window.addEventListener("DOMContentLoaded",()=>{window.Helpers.showActiveTheme(window.Helpers.getPreferredTheme()),m(),window.Helpers.initSidebarToggle(),document.querySelectorAll("[data-bs-theme-value]").forEach(e=>{e.addEventListener("click",()=>{const s=e.getAttribute("data-bs-theme-value");window.Helpers.setStoredTheme(templateName,s),window.Helpers.setTheme(s),window.Helpers.showActiveTheme(s,!0),window.Helpers.syncCustomOptions(s);let i=s;s==="system"&&(i=window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light");const l=document.querySelector(".template-customizer-semiDark");l&&(s==="dark"?l.classList.add("d-none"):l.classList.remove("d-none")),window.Helpers.switchImage(i)})})});let p=document.getElementsByClassName("dropdown-language");if(p.length){let i=function(l){document.documentElement.setAttribute("dir",l),l==="rtl"?localStorage.getItem("templateCustomizer-"+templateName+"--Rtl")!=="true"&&window.templateCustomizer&&window.templateCustomizer.setRtl(!0):localStorage.getItem("templateCustomizer-"+templateName+"--Rtl")==="true"&&window.templateCustomizer&&window.templateCustomizer.setRtl(!1)};var x=i;let e=p[0].querySelectorAll(".dropdown-item");const s=p[0].querySelector(".dropdown-item.active");i(s.dataset.textDirection);for(let l=0;l<e.length;l++)e[l].addEventListener("click",function(){let S=this.getAttribute("data-text-direction");window.templateCustomizer.setLang(this.getAttribute("data-language")),i(S)})}setTimeout(function(){let e=document.querySelector(".template-customizer-reset-btn");e&&(e.onclick=function(){window.location.href=baseUrl+"lang/en"})},1500);const h=document.querySelector(".dropdown-notifications-all"),w=document.querySelectorAll(".dropdown-notifications-read");h&&h.addEventListener("click",e=>{w.forEach(s=>{s.closest(".dropdown-notifications-item").classList.add("marked-as-read")})}),w&&w.forEach(e=>{e.addEventListener("click",s=>{e.closest(".dropdown-notifications-item").classList.toggle("marked-as-read")})}),document.querySelectorAll(".dropdown-notifications-archive").forEach(e=>{e.addEventListener("click",s=>{e.closest(".dropdown-notifications-item").remove()})}),[].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]')).map(function(e){return new bootstrap.Tooltip(e)});const y=function(e){e.type=="show.bs.collapse"||e.type=="show.bs.collapse"?e.target.closest(".accordion-item").classList.add("active"):e.target.closest(".accordion-item").classList.remove("active")};[].slice.call(document.querySelectorAll(".accordion")).map(function(e){e.addEventListener("show.bs.collapse",y),e.addEventListener("hide.bs.collapse",y)}),window.Helpers.setAutoUpdate(!0),window.Helpers.initPasswordToggle(),window.Helpers.initSpeechToText(),window.Helpers.initNavbarDropdownScrollbar();let b=document.querySelector("[data-template^='horizontal-menu']");if(b&&(window.innerWidth<window.Helpers.LAYOUT_BREAKPOINT?window.Helpers.setNavbarFixed("fixed"):window.Helpers.setNavbarFixed("")),window.addEventListener("resize",function(e){b&&(window.innerWidth<window.Helpers.LAYOUT_BREAKPOINT?window.Helpers.setNavbarFixed("fixed"):window.Helpers.setNavbarFixed(""),setTimeout(function(){window.innerWidth<window.Helpers.LAYOUT_BREAKPOINT?document.getElementById("layout-menu")&&document.getElementById("layout-menu").classList.contains("menu-horizontal")&&f.switchMenu("vertical"):document.getElementById("layout-menu")&&document.getElementById("layout-menu").classList.contains("menu-vertical")&&f.switchMenu("horizontal")},100))},!0),!(v||window.Helpers.isSmallScreen())&&(typeof window.templateCustomizer<"u"&&(window.templateCustomizer.settings.defaultMenuCollapsed?window.Helpers.setCollapsed(!0,!1):window.Helpers.setCollapsed(!1,!1)),typeof config<"u"&&config.enableMenuLocalStorage))try{localStorage.getItem("templateCustomizer-"+templateName+"--LayoutCollapsed")!==null&&window.Helpers.setCollapsed(localStorage.getItem("templateCustomizer-"+templateName+"--LayoutCollapsed")==="true",!1)}catch{}})();const C={container:"#autocomplete",placeholder:"Search [CTRL + K]",classNames:{detachedContainer:"d-flex flex-column",detachedFormContainer:"d-flex align-items-center justify-content-between border-bottom",form:"d-flex align-items-center",input:"search-control border-none",detachedCancelButton:"btn-search-close",panel:"flex-grow content-wrapper overflow-hidden position-relative",panelLayout:"h-100",clearButton:"d-none",item:"d-block"}};let d={};function L(){const r=$("#layout-menu").hasClass("menu-horizontal")?"search-horizontal.json":"search-vertical.json";fetch(assetsPath+"json/"+r).then(o=>{if(!o.ok)throw new Error("Failed to fetch data");return o.json()}).then(o=>{d=o,H()}).catch(o=>console.error("Error loading JSON:",o))}function H(){if(document.getElementById("autocomplete"))return autocomplete({...C,openOnFocus:!0,onStateChange({state:o,setQuery:c}){if(o.isOpen){document.body.style.overflow="hidden",document.body.style.paddingRight="var(--bs-scrollbar-width)";const t=document.querySelector(".aa-DetachedCancelButton");if(t&&(t.innerHTML='<span class="text-body-secondary">[esc]</span> <span class="icon-base icon-md ti tabler-x text-heading"></span>'),!window.autoCompletePS){const n=document.querySelector(".aa-Panel");n&&(window.autoCompletePS=new PerfectScrollbar(n))}}else o.status==="idle"&&o.query&&c(""),document.body.style.overflow="auto",document.body.style.paddingRight=""},render(o,c){var m;const{render:t,html:n,children:a,state:u}=o;if(!u.query){const p=n`
          <div class="p-5 p-lg-12">
            <div class="row g-4">
              ${Object.entries(d.suggestions||{}).map(([h,w])=>n`
                  <div class="col-md-6 suggestion-section">
                    <p class="search-headings mb-2">${h}</p>
                    <div class="suggestion-items">
                      ${w.map(g=>n`
                          <a href="${baseUrl}${g.url}" class="suggestion-item d-flex align-items-center">
                            <i class="icon-base ti ${g.icon}"></i>
                            <span>${g.name}</span>
                          </a>
                        `)}
                    </div>
                  </div>
                `)}
            </div>
          </div>
        `;t(p,c);return}if(!o.sections.length){t(n`
            <div class="search-no-results-wrapper">
              <div class="d-flex justify-content-center align-items-center h-100">
                <div class="text-center text-heading">
                  <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24">
                    <g
                      fill="none"
                      stroke="currentColor"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="0.6">
                      <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                      <path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2m-5-4h.01M12 11v3" />
                    </g>
                  </svg>
                  <h5 class="mt-2">No results found</h5>
                </div>
              </div>
            </div>
          `,c);return}t(a,c),(m=window.autoCompletePS)==null||m.update()},getSources(){const o=[];if(d.navigation){const c=Object.keys(d.navigation).filter(t=>t!=="files"&&t!=="members").map(t=>({sourceId:`nav-${t}`,getItems({query:n}){const a=d.navigation[t];return n?a.filter(u=>u.name.toLowerCase().includes(n.toLowerCase())):a},getItemUrl({item:n}){return baseUrl+n.url},templates:{header({items:n,html:a}){return n.length===0?null:a`<span class="search-headings">${t}</span>`},item({item:n,html:a}){return a`
                  <a href="${baseUrl}${n.url}" class="d-flex justify-content-between align-items-center">
                    <span class="item-wrapper"><i class="icon-base ti ${n.icon}"></i>${n.name}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" width="20px" height="20px" viewBox="0 0 24 24">
                      <g
                        fill="none"
                        stroke="currentColor"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="1.8"
                        color="currentColor">
                        <path d="M11 6h4.5a4.5 4.5 0 1 1 0 9H4" />
                        <path d="M7 12s-3 2.21-3 3s3 3 3 3" />
                      </g>
                    </svg>
                  </a>
                `}}}));o.push(...c),d.navigation.files&&o.push({sourceId:"files",getItems({query:t}){const n=d.navigation.files;return t?n.filter(a=>a.name.toLowerCase().includes(t.toLowerCase())):n},getItemUrl({item:t}){return baseUrl+t.url},templates:{header({items:t,html:n}){return t.length===0?null:n`<span class="search-headings">Files</span>`},item({item:t,html:n}){return n`
                  <a href="${baseUrl}${t.url}" class="d-flex align-items-center position-relative px-4 py-2">
                    <div class="file-preview me-2">
                      <img src="${assetsPath}${t.src}" alt="${t.name}" class="rounded" width="42" />
                    </div>
                    <div class="flex-grow-1">
                      <h6 class="mb-0">${t.name}</h6>
                      <small class="text-body-secondary">${t.subtitle}</small>
                    </div>
                    ${t.meta?n`
                          <div class="position-absolute end-0 me-4">
                            <span class="text-body-secondary small">${t.meta}</span>
                          </div>
                        `:""}
                  </a>
                `}}}),d.navigation.members&&o.push({sourceId:"members",getItems({query:t}){const n=d.navigation.members;return t?n.filter(a=>a.name.toLowerCase().includes(t.toLowerCase())):n},getItemUrl({item:t}){return baseUrl+t.url},templates:{header({items:t,html:n}){return t.length===0?null:n`<span class="search-headings">Members</span>`},item({item:t,html:n}){return n`
                  <a href="${baseUrl}${t.url}" class="d-flex align-items-center py-2 px-4">
                    <div class="avatar me-2">
                      <img src="${assetsPath}${t.src}" alt="${t.name}" class="rounded-circle" width="32" />
                    </div>
                    <div class="flex-grow-1">
                      <h6 class="mb-0">${t.name}</h6>
                      <small class="text-body-secondary">${t.subtitle}</small>
                    </div>
                  </a>
                `}}})}return o}})}document.addEventListener("keydown",r=>{(r.ctrlKey||r.metaKey)&&r.key==="k"&&(r.preventDefault(),document.querySelector(".aa-DetachedSearchButton").click())});document.documentElement.querySelector("#autocomplete")&&L();
