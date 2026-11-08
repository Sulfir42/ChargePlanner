/* This file is part of Jeedom - plugin ChargePlanner */

/* Sélecteur de commande Jeedom pour les champs « commande » de l'équipement. */
document.addEventListener('click', function (event) {
  var btn = event.target.closest('.chargePlannerSelectCmd');
  if (!btn) {
    return;
  }
  var input = btn.closest('.input-group').querySelector('input');
  jeedom.cmd.getSelectModal({ cmd: { type: btn.getAttribute('data-type') || 'info' } }, function (result) {
    input.value = result.human;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
});

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    _cmd = { configuration: {} };
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {};
  }
  var tr = '';
  tr += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span>';
  tr += '<input class="cmdAttr" data-l1key="logicalId" style="display:none;">';
  tr += '<input class="cmdAttr" data-l1key="type" style="display:none;">';
  tr += '<input class="cmdAttr" data-l1key="subType" style="display:none;"></td>';
  tr += '<td><div class="input-group">';
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom de la commande}}">';
  tr += '<span class="input-group-btn"><a class="cmdAction btn btn-sm btn-default roundedRight" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
  tr += '</div></td>';
  tr += '<td><span class="cmdAttr label label-info" data-l1key="type"></span> <span class="cmdAttr label label-default" data-l1key="subType"></span></td>';
  tr += '<td>';
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible">{{Afficher}}</label> ';
  if (_cmd.type === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>';
  }
  tr += '</td>';
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>';
  tr += '<td>';
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> ';
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a>';
  }
  tr += '</td>';

  var row = document.createElement('tr');
  row.innerHTML = tr;
  row.classList.add('cmd');
  row.setAttribute('data-cmd_id', init(_cmd.id));
  document.getElementById('table_cmd').querySelector('tbody').appendChild(row);
  row.setJeeValues(_cmd, '.cmdAttr');
}
