/*
 * Plugin Checklist — client behaviour.
 * Modified 2026 — moved out of inline PHP injection; user-facing strings are
 * translated in the browser via GLPI's native __()/_n() (the plugin's .mo is
 * shipped to the client by front/locale.php).
 */

(function () {
    'use strict';

    /*
     * Idempotence latch.
     *
     * Every listener in this file is bound on `document` and is never removed,
     * so evaluating the module twice would register a second copy of each and
     * fire every handler twice — a double confirm() dialog, a double toggle
     * POST, a double delete. Re-entry is not hypothetical in this codebase:
     * clEnsureSortable() below exists precisely because an init path can run
     * more than once. setup.php registers this file as a <head> asset, which
     * should load it exactly once, but the cost of being sure is two lines and
     * the failure mode is silent duplicate writes.
     */
    if (window.__clChecklistBound) return;
    window.__clChecklistBound = true;

    /*
     * Server-provided values (ajax CSRF token, SortableJS url) are handed over
     * as JSON in window.PLUGIN_CHECKLIST by the tab payload. Read LAZILY: this
     * file is loaded in <head>, the bootstrap object is emitted later, when the
     * dynamically loaded tab content is inserted into the DOM.
     */
    function clCfg() {
        return window.PLUGIN_CHECKLIST || {};
    }

    // GLPI 11 : CSRF for fetch goes via X-Glpi-Csrf-Token header
    function clFetch(url, fd) {
        return fetch(url, {
            method: "POST",
            body: fd,
            headers: {
                "X-Glpi-Csrf-Token": clCfg().csrf_token || "",
                "X-Requested-With": "XMLHttpRequest"
            }
        });
    }

    // Local SortableJS fallback loader (setup.php already registers the file;
    // this only covers a page where the asset hook did not run).
    function clEnsureSortable() {
        if (typeof Sortable !== "undefined") return;
        var url = clCfg().sortable_url;
        if (!url || document.getElementById("cl-sortable-js")) return;
        var s = document.createElement("script");
        s.id = "cl-sortable-js";
        s.src = url;
        document.head.appendChild(s);
    }
    clEnsureSortable();

    function clToggleKanban(id){
        var k=document.getElementById("cl-kanban-"+id);
        var c=document.getElementById("cl-chev-"+id);
        var card=document.getElementById("cl-card-"+id);
        if(!k) return;
        var open=k.style.display!=="none";
        k.style.display=open?"none":"block";
        if(c) c.classList.toggle("open",!open);
        if(card) card.classList.toggle("cl-card-open",!open);
        if(!open) setTimeout(function(){clInitSort(id);},80);
    }

    function clInitSort(id){
        clEnsureSortable();
        if(typeof Sortable==="undefined") return;
        var kanban=document.querySelector(".cl-kanban-wrap[data-cl-id=\""+id+"\"]");
        var base=kanban?kanban.dataset.ajaxUrl:"";
        ["todo","done"].forEach(function(col){
            var el=document.getElementById("cl-"+col+"-"+id);
            if(!el||el._si) return; el._si=true;
            Sortable.create(el,{
                group:"cl-"+id, handle:".cl-drag-hdl",
                animation:150, ghostClass:"sortable-ghost", chosenClass:"sortable-chosen",
                onEnd:function(e){
                    var from=e.from, to=e.to, itemId=parseInt(e.item.dataset.id);
                    if(from!==to) clToggleItem(itemId,base+"/move_item.php",false);
                    var ids=[].slice.call(to.querySelectorAll(".cl-item")).map(function(x){return x.dataset.id;});
                    clSaveOrder(to.dataset.clId,to.dataset.status,ids,base+"/reorder_items.php");
                    if(from!==to){
                        var fids=[].slice.call(from.querySelectorAll(".cl-item")).map(function(x){return x.dataset.id;});
                        clSaveOrder(from.dataset.clId,from.dataset.status,fids,base+"/reorder_items.php");
                    }
                    // No counter update here any more: this point is reached
                    // BEFORE any of the three requests above has answered, and
                    // the only number available synchronously is the DOM count.
                    // Each response carries the server's progress and repaints
                    // the card itself.
                }
            });
        });
    }

    function clToggleItem(itemId,url,move){
        var li=document.getElementById("cl-item-"+itemId);
        if(!li||li.dataset.lk) return;
        li.dataset.lk="1"; li.style.opacity=".4";
        var fd=new FormData(); fd.append("item_id",itemId);
        clFetch(url,fd)
        .then(function(r){return r.json();})
        .then(function(d){
            if(d.success){
                var p=li.parentElement, cid=p.dataset.clId;
                var t=document.getElementById("cl-"+d.new_status+"-"+cid);
                if(t) t.appendChild(li);
                clApplyProgress(cid,d.progress);
            }
            delete li.dataset.lk; li.style.opacity="1";
        }).catch(function(){delete li.dataset.lk;li.style.opacity="1";});
    }

    /*
     * The reorder response used to be discarded. It now carries the server's
     * progress, and reading it is what keeps the card correct after a drag
     * BETWEEN columns: move_item.php and the two reorder calls race, and
     * whichever answers last states the truth as of that moment.
     *
     * The .catch stays a silent no-op: a lost reorder is a cosmetic ordering
     * problem, and per the failure policy above a response we cannot read
     * simply leaves the bar as it was.
     */
    function clSaveOrder(clId,col,ids,url){
        var fd=new FormData();
        fd.append("cl_id",clId); fd.append("column",col);
        ids.forEach(function(id){fd.append("ids[]",id);});
        clFetch(url,fd)
        .then(function(r){return r.json();})
        .then(function(d){ if(d&&d.success) clApplyProgress(clId,d.progress); })
        .catch(function(){});
    }

    function clSetColBadge(list,n){
        if(!list) return;
        var col=list.closest(".cl-col");
        var b=col?col.querySelector(".cl-col-hdr .badge"):null;
        if(b) b.textContent=n;
    }

    /*
     * Apply the progress the SERVER computed. Formerly clUpdatePct(), which
     * counted <li> nodes in the two columns and did the percentage arithmetic
     * itself — a second implementation of PluginChecklistChecklist::
     * computeProgress() that drifted from it (it rounded where the server
     * floors, so 999/1000 drew a false 100 %). There is now exactly one
     * implementation, on the server, and this function only paints its result.
     *
     * `p` is the `progress` object from move_item.php / reorder_items.php /
     * add_item.php: {done, total, percent, complete}.
     *
     * MISSING OR FAILED PAYLOAD -> LEAVE THE BAR ALONE. Deliberate: the only
     * data available locally is the DOM node count, which is precisely the
     * second source of truth being deleted, and deriving from it is how the
     * card came to contradict the database in the first place. A stale bar is
     * at worst one action out of date and is corrected by the next successful
     * call or by a reload; a locally derived one is simply wrong while looking
     * authoritative. It is also the right answer on a failed request: nothing
     * changed in the database, so nothing should change on screen — even though
     * SortableJS has already moved the node, the counters stay truthful.
     */
    function clApplyProgress(clId,p){
        if(!p||typeof p.percent==="undefined") return;
        // Completion comes from the server's own flag, not from percent === 100:
        // an empty checklist is 0 % and NOT complete, and the server is the one
        // that knows the difference.
        var tone=p.complete?"bg-success":"bg-primary";
        var card=document.getElementById("cl-card-"+clId);
        if(card){
            var ct=card.querySelector(".cl-card-count"),
                bar=card.querySelector(".progress-bar"),
                bdg=card.querySelector(".cl-card-hdr-right .badge");
            if(ct) ct.textContent=p.done+"/"+p.total;
            if(bar){bar.style.width=p.percent+"%";bar.className="progress-bar "+tone;}
            if(bdg){bdg.textContent=p.percent+"%";bdg.className="badge "+tone;}
        }
        // Column header badges. The "to do" count is not a derived percentage:
        // a task is either todo or done, so the server's done/total settles it.
        clSetColBadge(document.getElementById("cl-todo-"+clId),p.total-p.done);
        clSetColBadge(document.getElementById("cl-done-"+clId),p.done);
    }

    /*
     * The slide-in animation for a node that has just arrived by ajax.
     *
     * .cl-card-new / .cl-item-new (public/css/checklist.css) were emitted by
     * the deleted JS builders, and by them ONLY — the PHP never wrote them,
     * which was the difference the pre-deletion diff of the two copies turned
     * up. Deleting the builders would have taken the animation with them and
     * left two orphan CSS rules behind.
     *
     * Applied here rather than added to the server's markup on purpose: "this
     * node just appeared" is not a fact about the checklist, it is a fact about
     * this insertion. The server renders the same card whether you created it a
     * moment ago or are reloading a year-old ticket, and a reload must not
     * animate every card on the page. So the markup stays identical either way
     * and the client, which is the only side that knows, adds the class.
     */
    function clMarkNew(el,cls){
        if(el && el.classList) el.classList.add(cls);
    }

    function clDeleteChecklist(id,url){
        if(!confirm(__('Delete this checklist and all its tasks?', 'checklist'))) return;
        var fd=new FormData(); fd.append("action","delete"); fd.append("cl_id",id);
        clFetch(url,fd).then(function(r){return r.json();}).then(function(d){
            if(d.success){
                var c=document.getElementById("cl-card-"+id);
                if(c) c.remove();
                // The response carries the server-rendered empty-state block;
                // it is only inserted if that really was the last card.
                clUpdateGlobalCount(d.empty_html);
            } else alert(__('Deletion failed.', 'checklist'));
        });
    }

    function clShowAddExc(id,url,moveUrl){
        var name=prompt(__('Exceptional task name:', 'checklist'),"");
        if(!name||!name.trim()) return;
        var desc=prompt(__('Description (optional):', 'checklist'),"") || "";
        var fd=new FormData();
        fd.append("cl_id",id); fd.append("name",name.trim()); fd.append("description",desc);
        clFetch(url,fd).then(function(r){return r.json();}).then(function(d){
            if(d.success){
                var todoList=document.getElementById("cl-todo-"+id);
                // d.html is renderItem()'s output — the same bytes a reload
                // would draw, escaping included.
                if(todoList && d.html){
                    todoList.insertAdjacentHTML("beforeend", d.html);
                    clMarkNew(todoList.lastElementChild, "cl-item-new");
                }
                clApplyProgress(id,d.progress);
            } else alert(__('An error occurred.', 'checklist')+" "+(d.error||__('Unknown')));
        });
    }

    /*
     * The checklist counter in the topbar, and the empty-state placeholder.
     *
     * `emptyHtml` is the server's rendering of that placeholder, passed in by
     * clDeleteChecklist from the delete response. This function used to build
     * it here with innerHTML, and used to create and destroy #cl-list as well.
     * Both are gone: #cl-list is now always present in the server's markup, so
     * there is nothing to create, and the placeholder is rendered by the same
     * PHP that renders it on a full page load.
     *
     * The count text stays — it is a translated string, not markup, and the
     * plural form is resolved by the catalogue the browser already has.
     */
    function clUpdateGlobalCount(emptyHtml){
        var n=document.querySelectorAll("#cl-list .cl-card").length;
        var sub=document.querySelector(".cl-topbar-sub");
        if(sub) sub.textContent=_n('%d checklist', '%d checklists', n, 'checklist').replace('%d', n);
        var emp=document.querySelector(".cl-empty");
        var list=document.getElementById("cl-list");
        if(n===0){
            if(!emp && emptyHtml && list) list.insertAdjacentHTML("beforebegin", emptyHtml);
        } else if(emp){
            emp.remove();
        }
    }

    // ── Searchable template picker ────────────────────────────────────────────
    function clCloseTplMenu(){
        var m=document.getElementById("cl-tpl-menu"), t=document.getElementById("cl-tpl-toggle");
        if(m) m.classList.remove("cl-open");
        if(t) t.classList.remove("cl-open");
    }
    function clFilterTpl(q){
        q=(q||"").toLowerCase(); var any=false;
        document.querySelectorAll("#cl-tpl-list .cl-tpl-opt").forEach(function(o){
            var match=(o.dataset.name||"").toLowerCase().indexOf(q)>=0;
            o.style.display=match?"":"none";
            if(match) any=true;
        });
        var nr=document.getElementById("cl-tpl-noresult");
        if(nr) nr.style.display=any?"none":"block";
    }
    function clResetTplPicker(){
        var hid=document.querySelector("#cl-tpl-picker [name=templates_id]");
        var tog=document.getElementById("cl-tpl-toggle");
        var def=document.querySelector("#cl-tpl-list .cl-tpl-opt[data-id=\"0\"]");
        if(hid) hid.value="0";
        if(tog && def) tog.textContent=def.dataset.name;
        document.querySelectorAll("#cl-tpl-list .cl-tpl-opt").forEach(function(o){
            o.classList.toggle("cl-tpl-active",o.dataset.id==="0");
        });
    }
    document.addEventListener("click",function(e){
        var tog=e.target.closest("#cl-tpl-toggle");
        if(tog){
            var m=document.getElementById("cl-tpl-menu");
            if(m.classList.contains("cl-open")){ clCloseTplMenu(); }
            else {
                m.classList.add("cl-open"); tog.classList.add("cl-open");
                var f=document.getElementById("cl-tpl-filter");
                if(f){ f.value=""; clFilterTpl(""); setTimeout(function(){f.focus();},30); }
            }
            return;
        }
        var opt=e.target.closest(".cl-tpl-opt");
        if(opt){
            var hid=document.querySelector("#cl-tpl-picker [name=templates_id]");
            var t=document.getElementById("cl-tpl-toggle");
            if(hid) hid.value=opt.dataset.id;
            if(t) t.textContent=opt.dataset.name;
            document.querySelectorAll("#cl-tpl-list .cl-tpl-opt").forEach(function(o){o.classList.remove("cl-tpl-active");});
            opt.classList.add("cl-tpl-active");
            clCloseTplMenu();
            return;
        }
        if(!e.target.closest("#cl-tpl-picker")) clCloseTplMenu();
    });
    document.addEventListener("input",function(e){
        if(e.target.id==="cl-tpl-filter") clFilterTpl(e.target.value);
    });
    document.addEventListener("keydown",function(e){
        if(e.target.id!=="cl-tpl-filter") return;
        if(e.key==="Enter"){
            e.preventDefault();
            var vis=[].slice.call(document.querySelectorAll("#cl-tpl-list .cl-tpl-opt")).filter(function(o){return o.style.display!=="none";});
            if(vis[0]) vis[0].click();
        } else if(e.key==="Escape"){ clCloseTplMenu(); }
    });

    // Event delegation on document -- works even if DOM is injected
    // after DOMContentLoaded (GLPI tabs loaded dynamically)
    document.addEventListener("click",function(e){
        var btn=e.target.closest("#cl-create-submit");
        if(!btn) return;
        var form=document.getElementById("cl-create-form");
        if(!form) return;
        var url=btn.dataset.ajaxUrl;
        var name=form.querySelector("[name=name]").value.trim();
        var itemtype=form.querySelector("[name=itemtype]").value;
        var items_id=form.querySelector("[name=items_id]").value;
        var tpl=form.querySelector("[name=templates_id]").value;
        if(!name){form.querySelector("[name=name]").classList.add("is-invalid");return;}
        btn.disabled=true;
        var fd=new FormData();
        fd.append("action","create"); fd.append("name",name);
        fd.append("itemtype",itemtype); fd.append("items_id",items_id);
        fd.append("templates_id",tpl);
        clFetch(url,fd).then(function(r){return r.json();}).then(function(d){
            if(d.success){
                // Close modal
                var modal=document.getElementById("clCreateModal");
                if(modal && typeof bootstrap!=="undefined"){
                    var bsModal=bootstrap.Modal.getInstance(modal);
                    if(bsModal) bsModal.hide();
                }
                // Reset form
                form.reset();
                clResetTplPicker();
                // Remove empty placeholder
                var emp=document.querySelector(".cl-empty");
                if(emp) emp.remove();
                /*
                 * Insert the card the SERVER rendered.
                 *
                 * This used to call clBuildCardHtml(d.id, d.name, d.total, …),
                 * a hand-kept copy of renderCard() that could only draw what
                 * those three values described — so it emitted empty kanban
                 * columns while stamping `total` into the column badge. From a
                 * 5-task template that produced "0/5" and a badge of 5 over a
                 * column containing nothing, and it stayed that way until the
                 * user reloaded the page. d.html arrives with the tasks in it.
                 *
                 * #cl-list is always in the server's markup now, so there is no
                 * container to create first.
                 */
                var list=document.getElementById("cl-list");
                if(list && d.html){
                    list.insertAdjacentHTML("beforeend", d.html);
                    clMarkNew(list.lastElementChild, "cl-card-new");
                }
                clUpdateGlobalCount();
                // Auto-open the new card kanban
                setTimeout(function(){ clToggleKanban(d.id); }, 100);
            } else {
                alert(__('An error occurred.', 'checklist')+" "+(d.error||__('Unknown')));
            }
            btn.disabled=false;
        }).catch(function(){btn.disabled=false;});
    });

    /*
     * Delegated confirmation for destructive submit buttons.
     *
     * The message travels in data-cl-confirm and is HTML-escaped by the server
     * like any other attribute value. It used to be interpolated straight into
     * a JS string literal inside onclick="return confirm('…')", so any locale
     * whose translation contains an apostrophe closed that literal early and
     * left a syntax error. An onclick that does not parse is never installed,
     * so the button submitted WITHOUT asking — silent deletion, not a dead
     * dialog.
     *
     * Bound at document level, immediately (not on DOMContentLoaded), because
     * GLPI injects tab and form content dynamically. Same shape as the
     * [data-clv-id] handler in checklist-validate.js.
     */
    document.addEventListener("click", function (e) {
        var el = e.target.closest ? e.target.closest("[data-cl-confirm]") : null;
        if (!el) return;
        if (!confirm(el.getAttribute("data-cl-confirm"))) {
            // preventDefault alone stops the submit; stopPropagation keeps the
            // click from reaching any outer handler that would act on it.
            e.preventDefault();
            e.stopPropagation();
        }
    });

    /*
     * ── DELEGATED ACTION DISPATCH ───────────────────────────────────────────
     *
     * The four behaviours below used to be inline onclick="" attributes emitted
     * by renderCard/renderKanban/renderItem (and mirrored in the two JS
     * builders above). They are now data-cl-* attributes read by this one
     * listener.
     *
     * WHY `document` AND NOTHING NARROWER. A delegated listener only fires
     * while the element is still inside the container it was delegated to, and
     * every one of these nodes moves:
     *
     *   - SortableJS re-parents an <li class="cl-item"> from #cl-todo-N to
     *     #cl-done-N and back. Delegating to either <ul> would arm the handler
     *     in one column and disarm it in the other.
     *   - clToggleKanban() flips .cl-kanban-wrap between display:none/block. It
     *     does not detach, but a future "destroy the kanban when collapsed"
     *     change would, and nothing would fail loudly.
     *   - A card created through the modal is injected whole into #cl-list —
     *     as server-rendered HTML, so a listener bound to the card's own
     *     subtree would never exist on it in the first place. (#cl-list itself
     *     used to be created and destroyed by clUpdateGlobalCount as the last
     *     checklist came and went; it is a stable server-rendered container
     *     since v2.1.0, but the argument for `document` does not rest on that.)
     *   - GLPI loads the whole tab by ajax after DOMContentLoaded, so at the
     *     time this file runs none of these containers exist yet.
     *
     * `document` survives all four. It is also what both existing precedents
     * use: [data-clv-id] in checklist-validate.js and [data-cl-confirm] above.
     * Registered immediately, not on DOMContentLoaded, for the same reason.
     *
     * CL_ACTIONS is a single selector list on purpose. closest() returns the
     * NEAREST matching ancestor, so a click on the delete button resolves to
     * the button and never to the .cl-card-hdr containing it — which is what
     * the old inline `event.stopPropagation()` bought, and what a second
     * listener could not provide (stopPropagation between two listeners on the
     * SAME node does nothing).
     */
    var CL_ACTIONS = "[data-cl-toggle-item],[data-cl-add-exc],[data-cl-delete-checklist],[data-cl-toggle-kanban]";

    document.addEventListener("click", function (e) {
        if (!e.target || !e.target.closest) return;
        var el = e.target.closest(CL_ACTIONS);
        if (!el) return;
        var d = el.dataset;
        if (d.clToggleItem !== undefined) {
            clToggleItem(parseInt(d.clToggleItem, 10), d.clMoveUrl);
        } else if (d.clAddExc !== undefined) {
            clShowAddExc(parseInt(d.clAddExc, 10), d.clAddUrl, d.clMoveUrl);
        } else if (d.clDeleteChecklist !== undefined) {
            clDeleteChecklist(parseInt(d.clDeleteChecklist, 10), d.clUrl);
        } else if (d.clToggleKanban !== undefined) {
            clToggleKanban(parseInt(d.clToggleKanban, 10));
        }
    });

    /*
     * The delete button's hover fade (formerly onmouseenter/onmouseleave) is
     * NOT here — it is a [data-cl-delete-checklist]:hover rule in
     * checklist.css. It could have been delegated too, but mouseenter and
     * mouseleave do not bubble, so delegation would have meant mouseover/
     * mouseout plus a relatedTarget containment test running on every hover
     * transition anywhere in GLPI, to fade one button. CSS costs nothing and,
     * unlike any handler, cannot come loose from a node that moves.
     */

    /*
     * ONE export, not four.
     *
     * These were exported "for the inline onclick handlers". Task 4 deleted the
     * last inline handler, and that left three of the four exported but
     * unreachable: nothing inside the bundle called them through `window.`
     * (the delegated dispatcher holds direct references), and nothing outside
     * it ever had a reason to — clToggleItem and clShowAddExc both need an ajax
     * URL that only the server-rendered data-cl-* attributes carry, and
     * clDeleteChecklist opens a confirm() dialog. An export nobody can call is
     * not an API, it is a promise to keep a signature stable for no one.
     *
     * clToggleKanban stays: it takes a checklist id and nothing else, it is
     * what the create flow uses to auto-open a freshly inserted card, and
     * "open this checklist's kanban" is a reasonable thing for a customisation
     * to want. The three functions still exist — they are module-private now.
     */
    window.clToggleKanban = clToggleKanban;
})();
