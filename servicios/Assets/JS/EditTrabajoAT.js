(function () {
  "use strict";

  function getIvaPercentage(form) {
    if (!form) {
      return 21;
    }

    // First try to get from hidden iva input
    const ivaInput = form.querySelector('input[name="iva"]');
    if (ivaInput && ivaInput.value) {
      return parseFloat(ivaInput.value);
    }

    // Fallback to fs_impuestos lookup
    // fs_impuestos is injected by the controller
    if (typeof fs_impuestos === "undefined") {
      return 21;
    }

    const codimpuestoInput = form.querySelector('input[name="codimpuesto"]');
    if (
      codimpuestoInput &&
      codimpuestoInput.value &&
      fs_impuestos[codimpuestoInput.value] !== undefined
    ) {
      return parseFloat(fs_impuestos[codimpuestoInput.value]);
    }

    return 21;
  }

  function updatePvp(precioInput) {
    const form = precioInput.closest("form");
    if (!form) return;

    const pvpInput = form.querySelector('input[name="pvp_con_iva"]');
    if (!pvpInput) return;

    let precioStr = precioInput.value;
    if (!precioStr) {
      pvpInput.value = "";
      return;
    }

    precioStr = precioStr.replace(/[^\d,.-]/g, "").replace(",", ".");
    const precio = parseFloat(precioStr);

    if (isNaN(precio)) {
      pvpInput.value = "";
      return;
    }

    const ivaPercentage = getIvaPercentage(form);
    const pvpConIva = precio * (1 + ivaPercentage / 100);
    pvpInput.value = pvpConIva.toFixed(2);
  }

  function updatePrecio(pvpInput) {
    // Bidirectional calculation: Calculate price from PVP
    const form = pvpInput.closest("form");
    if (!form) return;

    const precioInput = form.querySelector('input[name="precio"]');
    if (!precioInput) return;

    let pvpStr = pvpInput.value;
    if (!pvpStr) {
      precioInput.value = "";
      return;
    }

    pvpStr = pvpStr.replace(/[^\d,.-]/g, "").replace(",", ".");
    const pvp = parseFloat(pvpStr);

    if (isNaN(pvp)) {
      precioInput.value = "";
      return;
    }

    const ivaPercentage = getIvaPercentage(form);
    const precio = pvp / (1 + ivaPercentage / 100);
    precioInput.value = precio.toFixed(6);
  }

  ['input', 'change', 'keyup'].forEach(eventType => {
    document.addEventListener(eventType, function (event) {
      if (event.target.name === "precio") {
        updatePvp(event.target);
      } else if (event.target.name === "pvp_con_iva") {
        updatePrecio(event.target);
      } else if (event.target.name === "referencia" && eventType === 'change') {
        const reference = event.target.value;
        if (reference) {
          fetch(
            `index.php?page=EditTrabajoAT&action=get_product_data&reference=${reference}`
          )
            .then((response) => response.json())
            .then((data) => {
              const form = event.target.closest("form");
              if (data.codimpuesto) {
                const codimpuestoInput = form.querySelector(
                  'input[name="codimpuesto"]'
                );
                if (codimpuestoInput) {
                  codimpuestoInput.value = data.codimpuesto;
                }
  
                // Update hidden iva field if we can calculate it from fs_impuestos
                if (
                  typeof fs_impuestos !== "undefined" &&
                  fs_impuestos[data.codimpuesto] !== undefined
                ) {
                  const ivaInput = form.querySelector('input[name="iva"]');
                  if (ivaInput) {
                    ivaInput.value = fs_impuestos[data.codimpuesto];
                  }
                }
              }
  
              if (data.precio) {
                const precioInput = form.querySelector('input[name="precio"]');
                if (precioInput) {
                  precioInput.value = data.precio;
                }
              }
              
              // Always update PVP to reflect new tax, even if price didn't change
              const precioInput = form.querySelector('input[name="precio"]');
              if (precioInput) {
                updatePvp(precioInput);
              }
            })
            .catch((error) =>
              console.error("Error fetching product data:", error)
            );
        }
      }
    });
  });
})();
