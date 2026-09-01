(async function(){let k;const o=document.querySelector(".kanban-update-item-sidebar"),w=document.querySelector(".kanban-wrapper"),A=document.querySelector(".comment-editor"),b=document.querySelector(".kanban-add-new-board"),p=[].slice.call(document.querySelectorAll(".kanban-add-board-input")),h=document.querySelector(".kanban-add-board-btn"),y=document.querySelector("#due-date"),g=$(".select2"),f=document.querySelector("html").getAttribute("data-assets-path"),B=new bootstrap.Offcanvas(o),v=await fetch(f+"json/kanban.json");v.ok||console.error("error",v),k=await v.json(),y&&y.flatpickr({monthSelectorType:"static",static:!0,altInput:!0,altFormat:"j F, Y",dateFormat:"Y-m-d"});//! TODO: Update Event label and guest code to JS once select removes jQuery dependency
if(g.length){let e=function(t){if(!t.id)return t.text;var a="<div class='badge "+$(t.element).data("color")+"'> "+t.text+"</div>";return a};var O=e;g.each(function(){var t=$(this);t.wrap("<div class='position-relative'></div>").select2({placeholder:"Select Label",dropdownParent:t.parent(),templateResult:e,templateSelection:e,escapeMarkup:function(a){return a}})})}A&&new Quill(A,{modules:{toolbar:".comment-toolbar"},placeholder:"Write a Comment...",theme:"snow"});const x=()=>`
  <div class="dropdown">
      <i class="dropdown-toggle icon-base ti tabler-dots-vertical cursor-pointer"
         id="board-dropdown"
         data-bs-toggle="dropdown"
         aria-haspopup="true"
         aria-expanded="false">
      </i>
      <div class="dropdown-menu dropdown-menu-end" aria-labelledby="board-dropdown">
          <a class="dropdown-item delete-board" href="javascript:void(0)">
              <i class="icon-base ti tabler-trash icon-xs"></i>
              <span class="align-middle">Delete</span>
          </a>
          <a class="dropdown-item" href="javascript:void(0)">
              <i class="icon-base ti tabler-edit icon-xs"></i>
              <span class="align-middle">Rename</span>
          </a>
          <a class="dropdown-item" href="javascript:void(0)">
              <i class="icon-base ti tabler-archive icon-xs"></i>
              <span class="align-middle">Archive</span>
          </a>
      </div>
  </div>
`,S=()=>`
<div class="dropdown kanban-tasks-item-dropdown">
    <i class="dropdown-toggle icon-base ti tabler-dots-vertical"
       id="kanban-tasks-item-dropdown"
       data-bs-toggle="dropdown"
       aria-haspopup="true"
       aria-expanded="false">
    </i>
    <div class="dropdown-menu dropdown-menu-end" aria-labelledby="kanban-tasks-item-dropdown">
        <a class="dropdown-item" href="javascript:void(0)">Copy task link</a>
        <a class="dropdown-item" href="javascript:void(0)">Duplicate task</a>
        <a class="dropdown-item delete-task" href="javascript:void(0)">Delete</a>
    </div>
</div>
`,C=(e,t)=>`
<div class="d-flex justify-content-between flex-wrap align-items-center mb-2">
    <div class="item-badges">
        <div class="badge bg-label-${e}">${t}</div>
    </div>
    ${S()}
</div>
`,E=(e="",t=!1,a="",n="",r="")=>{const d=t?" pull-up":"",c=a?`avatar-${a}`:"",m=r?r.split(","):[];return e?e.split(",").map((s,l,H)=>{const M=n&&l!==H.length-1?` me-${n}`:"",F=m[l]||"";return`
            <div class="avatar ${c}${M} w-px-26 h-px-26"
                 data-bs-toggle="tooltip"
                 data-bs-placement="top"
                 title="${F}">
                <img src="${f}img/avatars/${s}"
                     alt="Avatar"
                     class="rounded-circle${d}">
            </div>
        `}).join(""):""},D=(e,t,a,n)=>`
<div class="d-flex justify-content-between align-items-center flex-wrap mt-2">
    <div class="d-flex">
        <span class="d-flex align-items-center me-2">
            <i class="icon-base ti tabler-paperclip me-1"></i>
            <span class="attachments">${e}</span>
        </span>
        <span class="d-flex align-items-center ms-2">
            <i class="icon-base ti tabler-message-2 me-1"></i>
            <span>${t}</span>
        </span>
    </div>
    <div class="avatar-group d-flex align-items-center assigned-avatar">
        ${E(a,!0,"xs",null,n)}
    </div>
</div>
`,i=new jKanban({element:".kanban-wrapper",gutter:"12px",widthBoard:"250px",dragItems:!0,boards:k,dragBoards:!0,addItemButton:!0,buttonContent:"+ Add Item",itemAddOptions:{enabled:!0,content:"+ Add New Item",class:"kanban-title-button btn btn-default border-none",footer:!1},click:e=>{const t=e,a=t.getAttribute("data-eid")?t.querySelector(".kanban-text").textContent:t.textContent,n=t.getAttribute("data-due-date"),r=new Date,d=r.getFullYear(),c=n?`${n}, ${d}`:`${r.getDate()} ${r.toLocaleString("en",{month:"long"})}, ${d}`,m=t.getAttribute("data-badge-text"),s=t.getAttribute("data-assigned");B.show(),o.querySelector("#title").value=a,o.querySelector("#due-date").nextSibling.value=c,$(".kanban-update-item-sidebar").find(g).val(m).trigger("change"),o.querySelector(".assigned").innerHTML="",o.querySelector(".assigned").insertAdjacentHTML("afterbegin",`${E(s,!1,"xs","1",e.getAttribute("data-members"))}
        <div class="avatar avatar-xs ms-1">
            <span class="avatar-initial rounded-circle bg-label-secondary">
                <i class="icon-base ti tabler-plus icon-xs text-heading"></i>
            </span>
        </div>`)},buttonClick:(e,t)=>{const a=document.createElement("form");a.setAttribute("class","new-item-form"),a.innerHTML=`
        <div class="mb-4">
            <textarea class="form-control add-new-item" rows="2" placeholder="Add Content" autofocus required></textarea>
        </div>
        <div class="mb-4">
            <button type="submit" class="btn btn-primary btn-sm me-3 waves-effect waves-light">Add</button>
            <button type="button" class="btn btn-label-secondary btn-sm cancel-add-item waves-effect waves-light">Cancel</button>
        </div>
      `,i.addForm(t,a),a.addEventListener("submit",n=>{n.preventDefault();const r=Array.from(document.querySelectorAll(`.kanban-board[data-id="${t}"] .kanban-item`));i.addElement(t,{title:`<span class="kanban-text">${n.target[0].value}</span>`,id:`${t}-${r.length+1}`}),Array.from(document.querySelectorAll(`.kanban-board[data-id="${t}"] .kanban-text`)).forEach(s=>{s.insertAdjacentHTML("beforebegin",S())}),Array.from(document.querySelectorAll(".kanban-item .kanban-tasks-item-dropdown")).forEach(s=>{s.addEventListener("click",l=>l.stopPropagation())}),Array.from(document.querySelectorAll(`.kanban-board[data-id="${t}"] .delete-task`)).forEach(s=>{s.addEventListener("click",()=>{const l=s.closest(".kanban-item").getAttribute("data-eid");i.removeElement(l)})}),a.remove()}),a.querySelector(".cancel-add-item").addEventListener("click",()=>a.remove())}});w&&new PerfectScrollbar(w);const u=document.querySelector(".kanban-container"),L=Array.from(document.querySelectorAll(".kanban-title-board")),q=Array.from(document.querySelectorAll(".kanban-item"));q.length&&q.forEach(e=>{const t=`<span class="kanban-text">${e.textContent}</span>`;let a="";e.getAttribute("data-image")&&(a=`
              <img class="img-fluid rounded mb-2"
                   src="${f}img/elements/${e.getAttribute("data-image")}">
          `),e.textContent="",e.getAttribute("data-badge")&&e.getAttribute("data-badge-text")&&e.insertAdjacentHTML("afterbegin",`${C(e.getAttribute("data-badge"),e.getAttribute("data-badge-text"))}${a}${t}`),(e.getAttribute("data-comments")||e.getAttribute("data-due-date")||e.getAttribute("data-assigned"))&&e.insertAdjacentHTML("beforeend",D(e.getAttribute("data-attachments")||0,e.getAttribute("data-comments")||0,e.getAttribute("data-assigned")||"",e.getAttribute("data-members")||""))}),Array.from(document.querySelectorAll('[data-bs-toggle="tooltip"]')).forEach(e=>{new bootstrap.Tooltip(e)});const T=Array.from(document.querySelectorAll(".kanban-tasks-item-dropdown"));T.length&&T.forEach(e=>{e.addEventListener("click",t=>{t.stopPropagation()})}),h&&h.addEventListener("click",()=>{p.forEach(e=>{e.value="",e.classList.toggle("d-none")})}),u&&u.append(b),L&&L.forEach(e=>{e.addEventListener("mouseenter",()=>{e.contentEditable="true"}),e.insertAdjacentHTML("afterend",x())}),Array.from(document.querySelectorAll(".delete-board")).forEach(e=>{e.addEventListener("click",()=>{const t=e.closest(".kanban-board").getAttribute("data-id");i.removeBoard(t)})}),Array.from(document.querySelectorAll(".delete-task")).forEach(e=>{e.addEventListener("click",()=>{const t=e.closest(".kanban-item").getAttribute("data-eid");i.removeElement(t)})});const j=document.querySelector(".kanban-add-board-cancel-btn");j&&j.addEventListener("click",()=>{p.forEach(e=>{e.classList.toggle("d-none")})}),b&&b.addEventListener("submit",e=>{e.preventDefault();const t=e.target.querySelector(".form-control").value.trim(),a=t.replace(/\s+/g,"-").toLowerCase();i.addBoards([{id:a,title:t}]);const n=document.querySelector(".kanban-board:last-child");if(n){const r=n.querySelector(".kanban-title-board");r.insertAdjacentHTML("afterend",x()),r.addEventListener("mouseenter",()=>{r.contentEditable="true"});const d=n.querySelector(".delete-board");d&&d.addEventListener("click",()=>{const c=d.closest(".kanban-board").getAttribute("data-id");i.removeBoard(c)})}p.forEach(r=>{r.classList.add("d-none")}),u&&u.append(b)}),o.addEventListener("hidden.bs.offcanvas",()=>{const e=o.querySelector(".ql-editor").firstElementChild;e&&(e.innerHTML="")}),o&&o.addEventListener("shown.bs.offcanvas",()=>{Array.from(o.querySelectorAll('[data-bs-toggle="tooltip"]')).forEach(t=>{new bootstrap.Tooltip(t)})})})();
