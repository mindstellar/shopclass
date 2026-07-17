/**
 * jshint esversion: 6
 * location jS, implement methods to add, edit, delete or manage locations
 * @author Navjot tomer
 * @licence GPLv3
 *
 * // Location constant
 * const sCountry = Params::getParam('country');
 * const sCountryCode = Params::getParam('country_code');
 * const sRegionId = Params::getParam('region');
 * const baseUrl = osc_admin_base_url();
 * const jsonExistingCountries = json_encode(Country::newInstance()->listNames());
 * const locationJsonUrl = osc_get_locations_json_url();
 *
 // Location constant
 var baseUrl = "<?php echo osc_admin_base_url(); ?>";
 var jsonExistingCountries = <?php echo json_encode(Country::newInstance()->listNames()) ?>;
 var locationJsonUrl = "<?php echo osc_get_locations_json_url() ?>";
 var sCountry = "<?php echo Params::getParam('country')?>";
 var sCountryCode = "<?php echo Params::getParam('country_code')?>";
 var sRegionId = "<?php echo Params::getParam('region')?>";
 //common text vars
 var stringAddCity = '<?php echo osc_esc_js(__('Add city')); ?>';
 var stringAddCountry = '<?php echo osc_esc_js(__('Add country')); ?>';
 var stringAddRegion = '<?php echo osc_esc_js(__('Add region')); ?>';
 var stringCity = '<?php echo osc_esc_js(__('City')); ?>';
 var stringCityName = "<?php echo osc_esc_js(__('City Name')); ?>";
 var stringCountry = '<?php echo osc_esc_js(__('Country')); ?>';
 var stringCountryCode = '<?php echo osc_esc_js(__('Country code')); ?>';
 var stringCountryName = '<?php echo osc_esc_js(__('Country name')); ?>';
 var stringDelete = '<?php echo osc_esc_js(__('Delete')); ?>';
 var stringDeleteTitle = "<?php echo osc_esc_js(__('Delete selected locations')); ?>";
 var stringDeleteWarning = "<?php echo osc_esc_js(__("This action can't be undone. Items associated to this location will be deleted. ". "Users from this location will be unlinked, but not deleted. Are you sure you want to continue?"));?>";
 var stringEdit = '<?php echo osc_esc_js(__('Edit')); ?>';
 var stringEnter = '<?php echo osc_esc_js(__('Enter')); ?>';
 var stringImport = '<?php echo osc_esc_js(__('Import')); ?>';
 var stringImportLocations = '<?php echo osc_esc_js(__('Import locations')); ?>';
 var stringImportWarning = "<?php echo osc_esc_js(__("Import a country with it's regions and cities from our database. " . "Already imported countries aren't shown.")); ?>";
 var stringName = '<?php echo osc_esc_js(__('Name')); ?>';
 var stringRegion = '<?php echo osc_esc_js(__('Region')); ?>';
 var stringRegionName = '<?php echo osc_esc_js(__('Region name')); ?>';
 var stringSave = '<?php echo osc_esc_js(__('Save')); ?>';
 var stringSelectOption = '<?php echo osc_esc_js(__('Select option')); ?>';
 var stringSlug = '<?php echo osc_esc_js(__('Slug')); ?>';
 var stringSlugError = "<?php echo osc_esc_js(__('The slug is not unique.'));?>";
 var stringSlugWarning = "<?php echo osc_esc_js(__('The slug has to be a unique string, could be left blank'));?>"
 var stringViewMore = "<?php echo osc_esc_js(__('View more')); ?>";
 */
/*jshint esversion: 6 */
/*jshint browser: true*/
"use strict";

// check if slugs are unique by making call to the server using fetchRequest
function checkSlugs(type) {
    const eCountrySlug = document.getElementById("e_country_slug");
    const eRegionSlug = document.getElementById("e_region_slug");
    const eCitySlug = document.getElementById("e_city_slug");

    let value;
    let url;
    if (type === "country") {
        value = eCountrySlug.value;
        url = baseUrl + "?page=ajax&action=country_slug" + "&slug=" + value;
        fetch(url)
            .then(response => response.json())
            .then((data) => {
                if (data.error === 1) {
                    eCountrySlug.setCustomValidity(stringSlugError);
                } else {
                    eCountrySlug.setCustomValidity("");
                }
            });
    } else if (type === "region") {
        value = eRegionSlug.value;
        url = baseUrl + "?page=ajax&action=region_slug" + "&slug=" + value;
        fetch(url)
            .then(response => response.json())
            .then((data) => {
                if (data.error === 1) {
                    eRegionSlug.setCustomValidity(stringSlugError);
                } else {
                    eRegionSlug.setCustomValidity("");
                }
            });

    } else if (type === "city") {
        value = eCitySlug.value;
        url = baseUrl + "?page=ajax&action=city_slug" + "&slug=" + value;
        fetch(url)
            .then(response => response.json())
            .then((data) => {
                if (data.error === 1) {
                    eCitySlug.setCustomValidity(stringSlugError);
                } else {
                    eCitySlug.setCustomValidity("");
                }
            });

    }
}

// Open location modal for location import
document.getElementById("b_import").addEventListener("click", function () {
    let countries;
    let i, l;
    // toggle locationModal
    toggleLocationModal(stringImportLocations, stringImportWarning, stringImport);
    // array list of input names and values
    let formInputs = [{
        name: "page",
        value: "settings"
    },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "locations_import"
        }
    ];

    fetchData(locationJsonUrl)
        .then((data) => {
            countries = data.locations;
            const importSelect = document.createElement("select");
            importSelect.setAttribute("class", "form-select form-select-sm");
            importSelect.setAttribute("name", "location");
            importSelect.setAttribute("id", "imported-location");
            importSelect.setAttribute("required", "required");

            const placeholder = document.createElement("option");
            placeholder.value = "";
            placeholder.textContent = stringSelectOption;
            importSelect.appendChild(placeholder);
            for (i = 0, l = countries.length; i < l; i++) {
                if (!jsonExistingCountries.includes(countries[i].s_country_name)) {
                    const opt = document.createElement("option");
                    opt.value = countries[i].s_file_name;
                    opt.textContent = countries[i].s_country_name;
                    importSelect.appendChild(opt);
                }
            }

            hiddenInputs.appendChild(importSelect);
        });
    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    document.getElementById("locationModal").querySelector('.osc-dialog-content').appendChild(hiddenInputs);
});
// Open location modal for country add
document.getElementById("b_new_country").addEventListener('click', function () {
    // toggle locationModal
    toggleLocationModal(
        stringAddCountry,
        "",
        stringAddCountry
    );
    let formInputs = [{
        name: "page",
        value: "settings"
    },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "add_country"
        },
        {
            name: "c_manual",
            value: "1"
        }
    ];
    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    // add more form inputs for country add with label and input

    let firstDiv = document.createElement("div");
    firstDiv.setAttribute("class", "mb-3");
    let labelOne = document.createElement("label");

    labelOne.textContent = stringCountry;
    let inputOne = document.createElement("input");
    inputOne.setAttribute("name", "country");
    inputOne.setAttribute("id", "country");
    inputOne.setAttribute("required", "required");
    inputOne.setAttribute("class", "form-control");
    inputOne.setAttribute("placeholder", stringCountryName);

    firstDiv.appendChild(labelOne);
    firstDiv.appendChild(inputOne);
    hiddenInputs.appendChild(firstDiv);

    let secondDiv = document.createElement("div");
    secondDiv.setAttribute("class", "mb-3");
    let labelTwo = document.createElement("label");

    labelTwo.textContent = stringCountryCode;
    let inputTwo = document.createElement("input");
    inputTwo.setAttribute("name", "c_country");
    inputTwo.setAttribute("minlength", "2");
    inputTwo.setAttribute("maxlength", "2");
    inputTwo.setAttribute("required", "required");
    inputTwo.setAttribute("id", "c_country");
    inputTwo.setAttribute("class", "form-control");
    inputTwo.setAttribute("placeholder", stringCountryCode);
    secondDiv.appendChild(labelTwo);
    secondDiv.appendChild(inputTwo);

    hiddenInputs.appendChild(secondDiv);
    document.getElementById("locationModal").querySelector('.osc-dialog-content').appendChild(hiddenInputs);

});
// Open location modal for region add
document.getElementById("b_new_region").addEventListener("click", function () {
    let modalTitle = stringAddRegion;
    let modalBody = "";
    // toggle locationModal
    toggleLocationModal(
        modalTitle,
        modalBody,
        stringAddRegion
    );
    let formInputs = [{
        name: "page",
        value: "settings"
    },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "add_region"
        },
        {
            name: "country_c_parent",
            value: document.getElementById("b_new_region").dataset.cCode
        },
        {
            name: "country_parent",
            value: document.getElementById("b_new_region").dataset.sCountry
        },
        {
            name: "r_manual",
            value: "1"
        },
        {
            name: "region_id",
            value: ""
        }
    ];
    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    // add more form inputs for region add with label and input
    let firstDiv = document.createElement("div");
    firstDiv.setAttribute("class", "mb-3");
    let labelOne = document.createElement("label");
    labelOne.textContent = stringRegion;
    let inputOne = document.createElement("input");
    inputOne.setAttribute("name", "region");
    inputOne.setAttribute("id", "region");
    inputOne.setAttribute("required", "required");
    inputOne.setAttribute("class", "form-control");
    inputOne.setAttribute("placeholder", stringRegionName);
    firstDiv.appendChild(labelOne);
    firstDiv.appendChild(inputOne);
    hiddenInputs.appendChild(firstDiv);
    document.getElementById("locationModal").querySelector('.osc-dialog-content').appendChild(hiddenInputs);
});
// Open location modal for city add
document.getElementById("b_new_city").addEventListener("click", function () {
    // toggle locationModal
    toggleLocationModal(
        stringAddCity,
        "",
        stringAddCity
    );
    let formInputs = [{
        name: "page",
        value: "settings"
    },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "add_city"
        },
        {
            name: "country_c_parent",
            value: document.getElementById("b_new_city").dataset.cCode
        },
        {
            name: "country_parent",
            value: document.getElementById("b_new_city").dataset.sCountry
        },
        {
            name: "region_parent",
            value: document.getElementById("b_new_city").dataset.idRegion
        },
        {
            name: "ci_manual",
            value: "1"
        },
        {
            name: "city_id",
            value: ""
        }
    ];
    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    // add more form inputs for city add with label and input
    let firstDiv = document.createElement("div");
    firstDiv.setAttribute("class", "mb-3");
    let labelOne = document.createElement("label");
    labelOne.textContent = stringCity;
    let inputOne = document.createElement("input");
    inputOne.setAttribute("name", "city");
    inputOne.setAttribute("id", "city");
    inputOne.setAttribute("required", "required");
    inputOne.setAttribute("class", "form-control");
    inputOne.setAttribute("placeholder", stringCityName);
    firstDiv.appendChild(labelOne);
    firstDiv.appendChild(inputOne);
    hiddenInputs.appendChild(firstDiv);
    document.getElementById("locationModal").querySelector(".osc-dialog-content").appendChild(hiddenInputs);
});
// Reset locationModal content when the native dialog closes
document.getElementById("locationModal").addEventListener("close", function () {
    document.getElementById("locationModal").querySelector(".osc-dialog-content").textContent = "";
    document.getElementById("locationModal").querySelector(".osc-dialog-title").textContent = "";
    document.getElementById("locationModal").querySelector("button[type='submit']").textContent = "";
});

//function to check if any checkbox is checked in given document element
function checkLocations(id) {
    let element = document.getElementById(id);
    // get only checked checkboxes from element
    let checkedCheckboxes = element.querySelectorAll("input[type='checkbox']:checked");
    if (checkedCheckboxes.length > 0) {
        // check if id is l_countries, i_regions, i_cities
        if (id === "l_countries") {
            //remove hide class from b_remove_country
            document.getElementById("b_remove_country").classList.remove("hide");
        } else if (id === "i_regions") {
            //remove hide class from b_remove_region
            document.getElementById("b_remove_region").classList.remove("hide");
        } else if (id === "i_cities") {
            //remove hide class from b_remove_city
            document.getElementById("b_remove_city").classList.remove("hide");
        }
    } else {
        // if no checkbox is checked
        if (id === "l_countries") {
            //add hide class to b_remove_country
            document.getElementById("b_remove_country").classList.add("hide");
        } else if (id === "i_regions") {
            //add hide class to b_remove_region
            document.getElementById("b_remove_region").classList.add("hide");
        } else if (id === "i_cities") {
            //add hide class to b_remove_city
            document.getElementById("b_remove_city").classList.add("hide");
        }
    }
}

// add even listener to b_remove_country, b_remove_region, b_remove_city click and run deleteMulitpleLocations function
document.getElementById("b_remove_country").addEventListener("click", function () {
    deleteMultipleLocations("country");
});
document.getElementById("b_remove_region").addEventListener("click", function () {
    deleteMultipleLocations("region");
});
document.getElementById("b_remove_city").addEventListener("click", function () {
    deleteMultipleLocations("city");
});

// function to delete multiple locations
function deleteMultipleLocations(type) {
    let listElement;
    // set list_element to l_countries, i_regions, i_cities
    if (type === "country") {
        listElement = "l_countries";
    } else if (type === "region") {
        listElement = "i_regions";
    } else if (type === "city") {
        listElement = "i_cities";
    }
    // create form inputs array
    let formInputs = [
        {
            name: "page",
            value: "settings"
        },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "delete_" + type
        }
    ];

    // get all checked checkboxes from list_element
    let checkedCheckboxes = document.getElementById(listElement)
        .querySelectorAll("input[type='checkbox']:checked");
    // loop through all checked checkboxes
    for (let i = 0; i < checkedCheckboxes.length; i++) {
        // get checkbox value
        let checkboxValue = checkedCheckboxes[i].value;
        formInputs.push({
            name: "id[]",
            value: checkboxValue
        });
        // add ids to array
        //ids.push(checkboxValue);
    }

    // toggle locationModal
    toggleLocationModal(stringDeleteTitle, "", stringDelete);

    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    // attach hiddenInputs to modal body
    document.getElementById("locationModal").querySelector(".osc-dialog-content").appendChild(hiddenInputs);

    // add delete help text to modal body
    let helpText = document.createElement("p");
    helpText.setAttribute("class", "help-block");
    helpText.textContent = stringDeleteWarning;
    document.getElementById("locationModal").querySelector(".osc-dialog-content").appendChild(helpText);
    // done
}

// Function to show locations based on type
function showLocations(type, element = null) {
    let dataElement;
    let idName;
    let idValue;
    let actionValue;
    let listDivId;
    let listDiv;

    dataElement = getLocationElement(type, element);

    if (dataElement) {
        if (type === "region") {
            document.getElementById("b_new_region").setAttribute("data-c-code", dataElement.dataset.id);
            document.getElementById("b_new_region").setAttribute("data-s-country", dataElement.dataset.sName);
            document.getElementById("b_new_region").classList.remove("hide");
            document.getElementById("b_new_city").setAttribute("data-c-code", dataElement.dataset.id);
            document.getElementById("b_new_city").setAttribute("data-s-country", dataElement.dataset.id);
            document.getElementById("b_new_city").setAttribute("data-id-region", "");
            document.getElementById("b_new_city").classList.add("hide");

            document.getElementById("i_cities").innerText = "";

            listDivId = "i_regions";
            listDiv = document.getElementById(listDivId);
            actionValue = "regions";
            idValue = "countryId";
            idName = dataElement.dataset.id;
        } else if (type === "city") {
            document.getElementById("b_new_city").classList.remove("hide");
            document.getElementById("b_new_city").setAttribute("data-id-region", dataElement.dataset.id);
            listDivId = "i_cities";
            listDiv = document.getElementById(listDivId);
            actionValue = "cities";
            idValue = "regionId";
            idName = dataElement.dataset.id;
        }
        let url = baseUrl + "index.php?page=ajax&action=" + actionValue + "&" + idValue + "=" + idName;
        //fetch data and create location list with new values.
        return fetchData(url).then(
            (data) => {
                if (data.length > 0) {
                    listDiv.textContent = "";
                    for (let i = 0, l = data.length; i < l; i++) {
                        let listItem = document.createElement("li");
                        listItem.setAttribute("class", "list-group-item");
                        listItem.setAttribute("id", type + "-" + data[i].pk_i_id);
                        listItem.setAttribute("data-id", data[i].pk_i_id);
                        listItem.setAttribute("data-s-name", data[i].s_name);
                        listItem.setAttribute("data-s-slug", data[i].s_slug);
                        //append checkbox, delete a tag, edit a tag and view a tag
                        let checkbox = document.createElement("input");
                        checkbox.setAttribute("type", "checkbox");
                        checkbox.setAttribute("name", type + "[]");
                        checkbox.setAttribute("value", data[i].pk_i_id);
                        checkbox.setAttribute("onclick", "checkLocations('" + listDivId + "');");
                        checkbox.setAttribute("class", "form-check-input me-1");
                        listItem.appendChild(checkbox);
                        let deleteA = document.createElement("a");
                        deleteA.setAttribute("href", "#");
                        deleteA.setAttribute("class", "close");
                        deleteA.setAttribute("data-id", data[i].pk_i_id);
                        deleteA.setAttribute("title", stringDelete);
                        deleteA.setAttribute("onclick", "deleteLocations(this,'" + type + "')");
                        //append i tag to a tag
                        let iTag = document.createElement("i");
                        iTag.setAttribute("class", "bi bi-x-circle-fill");
                        deleteA.appendChild(iTag);
                        listItem.appendChild(deleteA);
                        let editA = document.createElement("a");
                        editA.setAttribute("href", "#");
                        editA.setAttribute("class", "edit mx-1");
                        editA.setAttribute("data-id", data[i].pk_i_id);
                        editA.setAttribute("title", stringEdit);
                        editA.setAttribute("onclick", "editLocations(this,'" + type + "')");
                        editA.textContent = data[i].s_name;
                        listItem.appendChild(editA);
                        if (type === 'region') {
                            let viewMoreA = document.createElement("a");
                            viewMoreA.setAttribute("href", "#");
                            viewMoreA.setAttribute("class", "view-more float-end");
                            viewMoreA.setAttribute("data-id", data[i].pk_i_id);
                            viewMoreA.setAttribute("title", stringViewMore);
                            viewMoreA.setAttribute("onclick", "showLocations('city',this)");
                            viewMoreA.innerHTML = stringViewMore + "&raquo;";
                            listItem.appendChild(viewMoreA);
                        }
                        listDiv.appendChild(listItem);

                    }
                } else {
                    if(type === 'region') {
                        document.getElementById("b_new_region").classList.remove("hide");
                        document.getElementById("b_new_city").classList.add("hide");
                    }
                    listDiv.innerHTML = "<p class='text-center'>No " + type + " found</p>";
                }
            }
        );
    }
}

// function edit_locations
function editLocations(element, editType) {
    let dataElement = document.getElementById(editType + "-" + element.dataset.id)
    let formInputs = [{
        name: "page",
        value: "settings"
    },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "edit_" + editType
        }
    ];

    if (editType === "country") {
        formInputs.push({
            name: "country_code",
            value: dataElement.dataset.id
        });
    } else {
        formInputs.push({
            name: editType + "_id",
            value: dataElement.dataset.id
        });
    }

    // toggle locationModal
    toggleLocationModal(stringEdit + editType, "", stringSave);

    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    // add more form inputs for locations add with label and input
    let firstDiv = document.createElement("div");
    firstDiv.setAttribute("class", "mb-3");
    let labelOne = document.createElement('label');
    labelOne.textContent = stringName;
    let inputOne = document.createElement("input");
    inputOne.setAttribute("name", "e_" + editType);
    inputOne.setAttribute("id", "e_" + editType);
    inputOne.setAttribute("required", "required");
    inputOne.setAttribute("class", "form-control");
    inputOne.setAttribute("placeholder", stringEnter + editType + " " + stringName);
    inputOne.value = dataElement.dataset.sName;
    firstDiv.appendChild(labelOne);
    firstDiv.appendChild(inputOne);
    hiddenInputs.appendChild(firstDiv);
    let secondDiv = document.createElement("div");
    secondDiv.setAttribute("class", "mb-3");
    let labelTwo = document.createElement("label");
    labelTwo.textContent = stringSlug;
    let inputTwo = document.createElement("input");
    inputTwo.setAttribute("name", "e_" + editType + "_slug");
    inputTwo.setAttribute("id", "e_" + editType + "_slug");
    inputTwo.setAttribute("onchange", "checkSlugs('" + editType + "')");
    inputTwo.setAttribute("class", "form-control");
    inputTwo.setAttribute("placeholder", stringEnter + editType + " " + stringSlug);
    inputTwo.value = dataElement.dataset.sSlug;
    let helpDiv = document.createElement("div");
    helpDiv.setAttribute("class", "help-block");
    helpDiv.textContent = stringSlugWarning;
    secondDiv.appendChild(labelTwo);
    secondDiv.appendChild(inputTwo);
    secondDiv.appendChild(helpDiv);
    hiddenInputs.appendChild(secondDiv);
    document.getElementById("locationModal").querySelector(".osc-dialog-content").appendChild(hiddenInputs);

}

// function delete_locations
function deleteLocations(element, editType) {
    let formInputs = [{
        name: "page",
        value: "settings"
    },
        {
            name: "action",
            value: "locations"
        },
        {
            name: "type",
            value: "delete_" + editType
        },
        {
            name: "id[]",
            value: element.dataset.id
        }
    ];

    // toggle locationModal
    toggleLocationModal(
        stringDelete + editType,
        "",
        stringDelete
    );
    // Create form body from formInputs array using getHiddenInputs
    let hiddenInputs = getHiddenInputs(formInputs);
    // add more form inputs for locations add with label and input
    let firstDiv = document.createElement("div");
    firstDiv.setAttribute("class", "mb-3");
    firstDiv.textContent = stringDeleteWarning;
    document.getElementById("locationModal").querySelector(".osc-dialog-content").appendChild(firstDiv);
    document.getElementById("locationModal").querySelector(".osc-dialog-content").appendChild(hiddenInputs);
}

// Function to fetch json data from url
function fetchData(url, credentials = null) {
    let fetchRequest;
    if (null === credentials) {
        fetchRequest = fetch(url);
    } else {
        fetchRequest = fetch(url, {
            credentials: credentials
        });
    }
    return fetchRequest.then(
        (response) => {
            return response.json();
        }
    ).catch((err) => {
        console.log(err);
    });
}

// Toggle location modal with given title,body and submit button title
function toggleLocationModal(title, body, submitTitle) {
    let locationModal = document.getElementById("locationModal");
    locationModal.querySelector(".osc-dialog-content").textContent = body;
    locationModal.querySelector(".osc-dialog-title").textContent = title;
    locationModal.querySelector("button[type='submit']").textContent = submitTitle;
    locationModal.showModal();
}

// Function to create Form with hidden inputs and return form body
// @param data[{name:name,value:value},{...}]
function getHiddenInputs(data) {
    let fieldset = document.createElement("fieldset");
    let input;
    for (let i = 0, l = data.length; i < l; i++) {
        input = document.createElement("input");
        input.setAttribute("type", "hidden");
        input.setAttribute("name", data[i].name);
        input.setAttribute("value", data[i].value);
        fieldset.appendChild(input);
    }
    return fieldset;
}

// get parent location element with location data
function getLocationElement(type, element) {
    let dataElement;
    if (element === null) {
        if (type.length > 0 && sCountry.length > 0 && sCountryCode.length > 0) {
            if (type === 'region') {
                dataElement = document.getElementById("country-" + sCountryCode);
            } else if (type === 'city' && sRegionId.length > 0) {
                dataElement = document.getElementById("region-" + sRegionId);
            }
        }
    } else if (type === "region") {
        dataElement = document.getElementById("country-" + element.dataset.id);
    } else if (type === 'city') {
        dataElement = document.getElementById("region-" + element.dataset.id);
    }
    return dataElement;
}

if (sCountry !== "" && sCountryCode !== "") {
    showLocations("region").then(() => {
        if (sRegionId !== "") {
            showLocations(("city"));
        }
    });
}
// Done writing, blame Gujrati Poha for any error. ;-)