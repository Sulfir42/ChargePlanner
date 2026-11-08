/* This file is part of Jeedom - plugin ChargePlanner */

/* Sélecteur de commande Jeedom pour les champs « commande » de l'équipement. */
document.addEventListener('click', function (event) {
  var btn = event.target.closest('.chargePlannerSelectCmd');
  if (!btn) {
    return;
  }
  chargePlannerPickCmd(btn.getAttribute('data-type'), btn.closest('.input-group').querySelector('input'));
});

/*
 * Contournement d'une course dans le noyau Jeedom : getSelectModal() appelle
 * mod_insertCmd.setOptions() avant que la fenêtre cmd.human.insert ait
 * exécuté son script quand elle est ouverte depuis une autre fenêtre (page de
 * configuration du plugin). On pré-déclare l'objet avec un setOptions
 * d'attente ; le script de la fenêtre le remplace dès qu'il est chargé.
 */
function chargePlannerPickCmd(type, input) {
  if (window.mod_insertCmd == undefined) {
    window.mod_insertCmd = function () {};
    window.mod_insertCmd.options = { cmd: {}, eqLogic: {}, object: {} };
  }
  var waiting = function (opts, tries) {
    tries = tries || 0;
    if (window.mod_insertCmd.setOptions !== waiting) {
      window.mod_insertCmd.setOptions(opts);
    } else if (tries < 100) {
      setTimeout(function () { waiting(opts, tries + 1); }, 50);
    }
  };
  if (typeof window.mod_insertCmd.setOptions !== 'function') {
    window.mod_insertCmd.setOptions = waiting;
  }
  jeedom.cmd.getSelectModal({ cmd: { type: type || 'info' } }, function (result) {
    input.value = result.human;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}


/* Affiche les sections propres aux voitures, ou l'encart des équipements du plugin. */
function printEqLogic(_eqLogic) {
  var type = (_eqLogic && _eqLogic.configuration && _eqLogic.configuration.type) ? _eqLogic.configuration.type : 'vehicule';
  var isVehicle = (type === 'vehicule');
  document.querySelectorAll('.cp-vehicule').forEach(function (el) { el.style.display = isVehicle ? '' : 'none'; });
  document.querySelectorAll('.cp-site').forEach(function (el) { el.style.display = isVehicle ? 'none' : ''; });
}

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
  // Libellés en lecture seule : surtout pas de classe cmdAttr ici, sinon « type » et
  // « subType » apparaissent deux fois dans le formulaire et sont enregistrés en tableau.
  tr += '<td><span class="label label-info">' + init(_cmd.type) + '</span> <span class="label label-default">' + init(_cmd.subType) + '</span></td>';
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
