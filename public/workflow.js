document.querySelectorAll('[data-agreement-form]').forEach(form=>{
 const list=form.querySelector('[data-agreement-items]');
 function refresh(){list.querySelectorAll('[data-item]').forEach((row,index)=>{
  row.querySelectorAll('[name]').forEach(input=>input.name=input.name.replace(/items\[\d+\]/,'items['+index+']'));
  const type=row.querySelector('[data-item-type]').value;
  row.querySelectorAll('[data-item-fields]').forEach(group=>{const active=group.dataset.itemFields===type;group.hidden=!active;group.querySelectorAll('input').forEach(input=>{input.disabled=!active;input.required=active;});});
  const quantity=row.querySelector('[name$="[quantity]"]');quantity.max=type==='whisky'?'1':'100000';if(type==='whisky')quantity.value=1;
  row.querySelector('[data-remove-item]').disabled=list.children.length===1;
 });}
 form.addEventListener('change',e=>{if(e.target.matches('[data-item-type]'))refresh();});
 form.addEventListener('click',e=>{if(e.target.matches('[data-remove-item]')){e.target.closest('[data-item]').remove();refresh();}});
 form.querySelector('[data-add-item]').addEventListener('click',()=>{if(list.children.length>=50)return;const row=list.firstElementChild.cloneNode(true);row.querySelectorAll('input').forEach(input=>{input.value=input.name.endsWith('[quantity]')?'1':input.name.endsWith('[bottle_ml]')?'750':'';});list.append(row);refresh();});refresh();
});
