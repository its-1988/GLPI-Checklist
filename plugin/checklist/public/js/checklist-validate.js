/*
 * Plugin Checklist — "validate a checklist task" modal.
 * Modified 2026 — moved out of inline PHP injection; strings translated in the
 * browser via GLPI's native __() (the plugin's .mo is shipped by front/locale.php).
 */

(function () {
    'use strict';

    /*
     * Server-provided values (ajax base url, CSRF token) are handed over as JSON
     * in window.PLUGIN_CHECKLIST_VALIDATE by the timeline_actions hook. Read
     * LAZILY: this file is loaded in <head>, the bootstrap object is echoed
     * later, when the timeline renders. Capturing it at top level would freeze
     * it to {} and every request would ship an empty CSRF token.
     */
    function clvCfg() {
        return window.PLUGIN_CHECKLIST_VALIDATE || {};
    }

    /*
     * The modal shell, rendered by the server.
     *
     * It was built here from string literals, with a clvEsc() that escaped &, <,
     * > and " but NOT the apostrophe — a second, weaker escaper alongside PHP's
     * htmlspecialchars. Both are gone: PluginChecklistChecklist renders every
     * fragment this file inserts (v2.1.0 Task 5), and hook.php hands the shell
     * and the two placeholders over on the bootstrap object.
     *
     * Still appended to <body> rather than emitted in place: a Bootstrap modal
     * wants to be a direct child of body for backdrop and stacking, and GLPI
     * repaints the timeline asynchronously — markup left inside it would be
     * thrown away mid-render.
     */
    function clvEnsureModal(){
        if(document.getElementById("clvModal")) return;
        var html=clvCfg().modal_html;
        if(!html) return;
        var w=document.createElement("div");
        w.innerHTML=html;
        document.body.appendChild(w.firstElementChild);
    }

    // GLPI 11 : CSRF for fetch goes via X-Glpi-Csrf-Token header
    function clvFetch(ep,fd){
        var cfg=clvCfg();
        return fetch((cfg.ajax||"")+"/"+ep,{method:"POST",body:fd,
            headers:{"X-Glpi-Csrf-Token":cfg.csrf||"","X-Requested-With":"XMLHttpRequest"}});
    }

    function clvOpen(id,type){
        clvEnsureModal();
        var cfg=clvCfg();
        var el=document.getElementById("clvModal");
        var modal=bootstrap.Modal.getOrCreateInstance(el);
        var body=document.getElementById("clvBody");
        body.innerHTML=cfg.loading_html||"";
        modal.show();

        var fd=new FormData(); fd.append("itemtype",type); fd.append("items_id",id);
        clvFetch("get_todo_items.php",fd)
        .then(function(r){return r.json();})
        .then(function(d){
            /*
             * One branch, not two. The endpoint renders the "All tasks are
             * already done!" state itself, so an empty list is just another
             * piece of HTML to insert rather than a case this file has to
             * recognise and draw. Only a genuinely failed response falls
             * through to the error placeholder.
             */
            body.innerHTML=(d&&d.success&&d.html)?d.html:(cfg.error_html||"");
        })
        .catch(function(){
            body.innerHTML=cfg.error_html||"";
        });

        document.getElementById("clvSubmit").onclick=function(){
            var cbs=[].slice.call(document.querySelectorAll(".clvcb:checked"));
            if(!cbs.length) return;
            var btn=this; btn.disabled=true;
            // ONE request for the whole selection: the batch endpoint aggregates
            // the whole lot into a single followup (and a single notification).
            var fd=new FormData();
            cbs.forEach(function(c){fd.append("item_ids[]",c.value);});
            clvFetch("validate_items.php",fd)
            .then(function(r){return r.json();})
            .then(function(){
                bootstrap.Modal.getInstance(document.getElementById("clvModal")).hide();
                window.location.reload();
            })
            .catch(function(){btn.disabled=false;alert(__('An error occurred.', 'checklist'));});
        };
    }

    /*
     * Reached from the delegated click handler below and from PHP-rendered
     * markup — MUST stay global.
     */
    window.clOpenValidateModal = clvOpen;

    // Immediate listener — works even if the DOM is already loaded (GLPI loads
    // its tabs dynamically).
    document.addEventListener("click",function(e){
        var b=e.target.closest("[data-clv-id]");
        if(b) window.clOpenValidateModal(b.dataset.clvId,b.dataset.clvType);
    });

    // Move the "validate checklist task" entry into the ticket Answer (▾) actions
    // menu; if that menu is absent (single action type / solved ticket), restyle it
    // as a small button where it stands.
    function clRelocate(){
        var li=document.querySelector("li.cl-tl-validate");
        // "Absent" now means "the timeline has not rendered yet", NOT "done":
        // inline, this ran after the <li>; from <head> it runs well before it.
        // Returning true here would disarm the timers/observer for good.
        if(!li) return false;
        if(li.getAttribute("data-cl-moved")) return true;
        var menu=document.querySelector(".main-actions .dropdown-menu");
        if(menu){ li.setAttribute("data-cl-moved","1"); menu.appendChild(li); return true; }
        var a=li.querySelector("a.dropdown-item");
        if(a){ li.setAttribute("data-cl-moved","1"); a.className="btn btn-sm btn-outline-success"; return true; }
        return false;
    }
    if(!clRelocate()){
        [150,500,1200,2500].forEach(function(ms){setTimeout(clRelocate,ms);});
        if(window.MutationObserver){
            var _clt=null,_cln=0;
            var _clo=new MutationObserver(function(){
                if(_clt)clearTimeout(_clt);
                _clt=setTimeout(function(){_cln++;if(clRelocate()||_cln>60)_clo.disconnect();},200);
            });
            // Loaded from <head>, document.body can still be null here; the
            // inline version always ran with a body present.
            if(document.body){
                _clo.observe(document.body,{childList:true,subtree:true});
            } else {
                document.addEventListener("DOMContentLoaded",function(){
                    if(document.body)_clo.observe(document.body,{childList:true,subtree:true});
                });
            }
        }
    }
})();
