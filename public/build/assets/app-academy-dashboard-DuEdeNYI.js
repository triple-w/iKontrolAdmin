document.addEventListener("DOMContentLoaded",function(T){let d,u,m,i;d=config.colors.textMuted,u=config.colors.headingColor,m=config.colors.borderColor,i=config.fontFamily;const l={donut:{series1:"color-mix(in sRGB, "+config.colors.success+" 80%, "+config.colors.black+")",series2:"color-mix(in sRGB, "+config.colors.success+" 90%, "+config.colors.black+")",series3:config.colors.success,series4:"color-mix(in sRGB, "+config.colors.success+" 80%, "+config.colors.cardColor+")",series5:"color-mix(in sRGB, "+config.colors.success+" 60%, "+config.colors.cardColor+")",series6:"color-mix(in sRGB, "+config.colors.success+" 40%, "+config.colors.cardColor+")"}},f=document.querySelector("#leadsReportChart"),v={chart:{height:170,width:150,parentHeightOffset:0,type:"donut"},labels:["36h","56h","16h","32h","56h","16h"],series:[23,35,10,20,35,23],colors:[l.donut.series1,l.donut.series2,l.donut.series3,l.donut.series4,l.donut.series5,l.donut.series6],stroke:{width:0},dataLabels:{enabled:!1,formatter:function(e,a){return parseInt(e)+"%"}},legend:{show:!1},tooltip:{theme:!1},grid:{padding:{top:0}},plotOptions:{pie:{donut:{size:"70%",labels:{show:!0,value:{fontSize:"1.125rem",fontFamily:i,color:u,fontWeight:500,offsetY:-20,formatter:function(e){return parseInt(e)+"%"}},name:{offsetY:20,fontFamily:i},total:{show:!0,fontSize:".9375rem",label:"Total",color:d,formatter:function(e){return"231h"}}}}}}};typeof f!==void 0&&f!==null&&new ApexCharts(f,v).render();const p=document.querySelector("#horizontalBarChart"),b={chart:{height:300,type:"bar",toolbar:{show:!1}},plotOptions:{bar:{horizontal:!0,barHeight:"60%",distributed:!0,startingShape:"rounded",borderRadiusApplication:"end",borderRadius:7}},grid:{strokeDashArray:10,borderColor:m,xaxis:{lines:{show:!0}},yaxis:{lines:{show:!1}},padding:{top:-35,bottom:-12}},colors:[config.colors.primary,config.colors.info,config.colors.success,config.colors.secondary,config.colors.danger,config.colors.warning],fill:{opacity:[1,1,1,1,1,1]},dataLabels:{enabled:!0,style:{colors:[config.colors.white],fontWeight:400,fontSize:"13px",fontFamily:i},formatter:function(e,a){return b.labels[a.dataPointIndex]},offsetX:0,dropShadow:{enabled:!1}},labels:["UI Design","UX Design","Music","Animation","React","SEO"],series:[{data:[35,20,14,12,10,9]}],xaxis:{categories:["6","5","4","3","2","1"],axisBorder:{show:!1},axisTicks:{show:!1},labels:{style:{colors:d,fontFamily:i,fontSize:"13px"},formatter:function(e){return`${e}%`}}},yaxis:{max:35,labels:{style:{colors:[d],fontFamily:i,fontSize:"13px"}}},tooltip:{enabled:!0,style:{fontSize:"12px"},onDatasetHover:{highlightDataSeries:!1},custom:function({series:e,seriesIndex:a,dataPointIndex:t,w:r}){return'<div class="px-3 py-2"><span>'+e[a][t]+"%</span></div>"}},legend:{show:!1}};typeof p!==void 0&&p!==null&&new ApexCharts(p,b).render();function y(e,a,t){return{chart:{height:t=="true"?58:48,width:t=="true"?58:38,type:"radialBar"},plotOptions:{radialBar:{hollow:{size:t=="true"?"50%":"25%"},dataLabels:{show:t=="true",value:{offsetY:-10,fontSize:"15px",fontWeight:500,fontFamily:i,color:u}},track:{background:config.colors_label.secondary}}},stroke:{lineCap:"round"},colors:[e],grid:{padding:{top:t=="true"?-12:-15,bottom:t=="true"?-17:-15,left:t=="true"?-17:-5,right:-15}},series:[a],labels:t=="true"?[""]:["Progress"]}}const g=document.querySelectorAll(".chart-progress");g&&g.forEach(function(e){const a=config.colors[e.dataset.color],t=e.dataset.series,r=e.dataset.progress_variant,o=y(a,t,r);new ApexCharts(e,o).render()});const h=document.querySelector(".datatables-academy-course"),w={angular:'<span class="badge bg-label-danger rounded p-1_5"><i class="icon-base ti tabler-brand-angular icon-28px"></i></span>',figma:'<span class="badge bg-label-warning rounded p-1_5"><i class="icon-base ti tabler-brand-figma icon-28px"></i></span>',react:'<span class="badge bg-label-info rounded p-1_5"><i class="icon-base ti tabler-brand-react icon-28px"></i></span>',art:'<span class="badge bg-label-success rounded p-1_5"><i class="icon-base ti tabler-color-swatch icon-28px"></i></span>',fundamentals:'<span class="badge bg-label-primary rounded p-1_5"><i class="icon-base ti tabler-diamond icon-28px"></i></span>'};if(h){let e=document.createElement("h5");e.classList.add("card-title","mb-0","text-nowrap","text-md-start","text-center"),e.innerHTML="Course you are taking",new DataTable(h,{ajax:assetsPath+"json/app-academy-dashboard.json",columns:[{data:"id"},{data:"id",orderable:!1,render:DataTable.render.select()},{data:"course name"},{data:"time"},{data:"progress"},{data:"status"}],columnDefs:[{className:"control",searchable:!1,orderable:!1,responsivePriority:2,targets:0,render:function(a,t,r,o){return""}},{targets:1,orderable:!1,searchable:!1,responsivePriority:3,checkboxes:!0,render:function(){return'<input type="checkbox" class="dt-checkboxes form-check-input">'},checkboxes:{selectAllRender:'<input type="checkbox" class="form-check-input">'}},{targets:2,responsivePriority:2,render:(a,t,r)=>{const{logo:o,course:s,user:n,image:c}=r,C=c?`<img src="${assetsPath}img/avatars/${c}" alt="Avatar" class="rounded-circle">`:(()=>{const x=["success","danger","warning","info","dark","primary","secondary"],$=x[Math.floor(Math.random()*x.length)],R=(n.match(/\b\w/g)||[]).reduce((S,k)=>S+k.toUpperCase(),"");return`<span class="avatar-initial rounded-circle bg-label-${$}">${R}</span>`})();return`
                  <div class="d-flex align-items-center">
                      <span class="me-4">${w[o]}</span>
                      <div>
                          <a class="text-heading text-truncate fw-medium mb-2 text-wrap" href="${baseUrl}app/academy/course-details">
                              ${s}
                          </a>
                          <div class="d-flex align-items-center mt-1">
                              <div class="avatar-wrapper me-2">
                                  <div class="avatar avatar-xs">
                                      ${C}
                                  </div>
                              </div>
                              <small class="text-nowrap text-heading">${n}</small>
                          </div>
                      </div>
                  </div>
              `}},{targets:3,responsivePriority:3,render:a=>{const t=moment.duration(a),r=Math.floor(t.asHours()),o=Math.floor(t.asMinutes())-r*60;return`<span class="fw-medium text-nowrap text-heading">${`${r}h ${o}m`}</span>`}},{targets:4,render:(a,t,r)=>{const{status:o,number:s}=r;return`
                  <div class="d-flex align-items-center gap-3">
                      <p class="fw-medium mb-0 text-heading">${o}</p>
                      <div class="progress w-100" style="height: 8px;">
                          <div
                              class="progress-bar"
                              style="width: ${o}"
                              aria-valuenow="${o}"
                              aria-valuemin="0"
                              aria-valuemax="100">
                          </div>
                      </div>
                      <small>${s}</small>
                  </div>
              `}},{targets:5,render:(a,t,r)=>{const{user_number:o,note:s,view:n}=r;return`
                  <div class="d-flex align-items-center justify-content-between">
                      <div class="d-flex align-items-center">
                          <i class="icon-base ti tabler-users icon-lg me-1_5 text-primary"></i>
                          <span>${o}</span>
                      </div>
                      <div class="d-flex align-items-center">
                          <i class="icon-base ti tabler-book icon-lg me-1_5 text-info"></i>
                          <span>${s}</span>
                      </div>
                      <div class="d-flex align-items-center">
                          <i class="icon-base ti tabler-video icon-lg me-1_5 text-danger"></i>
                          <span>${n}</span>
                      </div>
                  </div>
              `}}],select:{style:"multi",selector:"td:nth-child(2)"},order:[[2,"desc"]],layout:{topStart:{rowClass:"row card-header border-bottom mx-0 px-3 py-0",features:[e]},topEnd:{search:{placeholder:"Search Course",text:"_INPUT_"}},bottomStart:{rowClass:"row mx-3 justify-content-between",features:["info"]},bottomEnd:"paging"},lengthMenu:[5],language:{paginate:{next:'<i class="icon-base ti tabler-chevron-right scaleX-n1-rtl icon-18px"></i>',previous:'<i class="icon-base ti tabler-chevron-left scaleX-n1-rtl icon-18px"></i>',first:'<i class="icon-base ti tabler-chevrons-left scaleX-n1-rtl icon-18px"></i>',last:'<i class="icon-base ti tabler-chevrons-right scaleX-n1-rtl icon-18px"></i>'}},responsive:{details:{display:DataTable.Responsive.display.modal({header:function(a){return"Details of "+a.data().order}}),type:"column",renderer:function(a,t,r){const o=r.map(function(s){return s.title!==""?`<tr data-dt-row="${s.rowIndex}" data-dt-column="${s.columnIndex}">
                      <td>${s.title}:</td>
                      <td>${s.data}</td>
                    </tr>`:""}).join("");if(o){const s=document.createElement("div");s.classList.add("table-responsive");const n=document.createElement("table");s.appendChild(n),n.classList.add("table"),n.classList.add("datatables-basic");const c=document.createElement("tbody");return c.innerHTML=o,n.appendChild(c),s}return!1}}}})}setTimeout(()=>{[{selector:".dt-search .form-control",classToRemove:"form-control-sm"},{selector:".dt-length .form-select",classToRemove:"form-select-sm"},{selector:".dt-layout-table",classToRemove:"row mt-2"},{selector:".dt-layout-full",classToRemove:"col-md col-12",classToAdd:"table-responsive"}].forEach(({selector:a,classToRemove:t,classToAdd:r})=>{document.querySelectorAll(a).forEach(o=>{t.split(" ").forEach(s=>o.classList.remove(s)),r&&o.classList.add(r)})})},100)});
