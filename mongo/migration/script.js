/* 
 * Idempotent migration script goes here.
 * Please try to avoid using migration script and instead make special treament in the code!
 */

// BRCD-576 (Deactivate / reactivate payment gateway)
db.subscribers.find({type: 'account', payment_gateway: {$exists: 1}, 'payment_gateway.active': {$exists: 0}}).forEach(
		function (obj) {
			var ele = {active: obj.payment_gateway};
			obj.payment_gateway = ele;
			db.subscribers.save(obj);
		}
);

// BRCD-594 (Override product price in plan / plan includes products - migration script)
//  run over rates
//  fill gropus dictionary
//  move override prices from rate to plan
var gruopD = {};
db.rates.find({'to': {$gt: new Date()}}).forEach(function (product) {
	var rates = product.rates;
	for (var type in rates) {
		for (var planKey in rates[type]) {
			if (planKey == "groups") {
				for (var gruop in rates[type][planKey]) {
					var groupName = rates[type][planKey][gruop];
					if (!gruopD.hasOwnProperty(groupName)) {
						gruopD[groupName] = [];
					}
					var alreadyExist = false;
					for (var i = 0; i < gruopD[groupName].length; i++) {
						if (gruopD[groupName][i] == product.key)
							alreadyExist = true;
					}
					if (!alreadyExist)
						gruopD[groupName].push(product.key);
				}
				delete rates[type][planKey];
			} else if (planKey != "BASE") {
				planDoc = db.plans.findOne({'name': planKey, 'to': {$gt: new Date()}});
				if (planDoc != null) {
					if (!planDoc.hasOwnProperty('rates')) {
						planDoc['rates'] = {};
					}
					if (!planDoc['rates'].hasOwnProperty(product.key)) {
						planDoc['rates'][product.key] = {};
					}
					planDoc['rates'][product.key][type] = rates[type][planKey];
				}
				db.plans.save(planDoc);
				delete rates[type][planKey];
			}
		}
	}
	product.rates = rates;
	db.rates.save(product);
});
//  run over plans
//  add rates array to each group
db.plans.find({'to': {$gt: new Date()}}).forEach(function (plan) {
	if (plan.hasOwnProperty("include")) {
		var groupsInPlan = plan.include.groups;
		for (var group in groupsInPlan) {
			if (gruopD.hasOwnProperty(group)) {
				groupsInPlan[group]["rates"] = gruopD[group];
			}
		}
		plan.include.groups = groupsInPlan;
		db.plans.save(plan);
	}
});
//  run over services
//  add rates array to each group
db.services.find({'to': {$gt: new Date()}}).forEach(function (service) {
	if (service.hasOwnProperty("include")) {
		var groupsInService = service.include.groups;
		for (var group in groupsInService) {
			if (gruopD.hasOwnProperty(group)) {
				groupsInService[group]["rates"] = gruopD[group];
			}
		}
		service.include.groups = groupsInService;
		db.services.save(service);
	}
});

// Add zip code account system field if it doesn't yet exist
var lastConfig = db.config.find().sort({_id: -1}).limit(1).pretty()[0];
delete lastConfig['_id'];
var found_zip_code = false;
lastConfig.subscribers.account.fields.forEach(function (field) {
	if (field.field_name == "zip_code") {
		found_zip_code = true;
		field.unique = false;
		field.generated = false;
		field.editable = true;
		field.mandatory = true;
		field.system = true;
		field.show_in_list = false;
		field.display = true;
	}
})
if (!found_zip_code) {
	lastConfig.subscribers.account.fields.push({
		"field_name": "zip_code",
		"title": "Zip code",
		"generated": false,
		"unique": false,
		"editable": true,
		"mandatory": true,
		"system": true,
		"show_in_list": false,
		"display": true
	});
}
db.config.insert(lastConfig);

// BRCD-614
var serviceDic = {}
db.subscribers.find({type: "subscriber", services: {$ne: [], $exists: 1}}).forEach(function (obj) {
	var services = [];
	for (var i in obj.services) {
		var srvname = obj.services[i].name ? obj.services[i].name : obj.services[i];
		var dicKey = srvname + obj.sid;
		if (!serviceDic[dicKey]) {
			serviceDic[dicKey] = {name: srvname, from: obj.from, to: obj.to};
		} else {
			if (obj.from < serviceDic[dicKey].from) {
				serviceDic[dicKey].from = obj.from;
			}
			if (obj.to > serviceDic[dicKey].to) {
				serviceDic[dicKey].to = obj.to;
			}
		}

	}
});
db.subscribers.find({type: "subscriber", services: {$ne: [], $exists: 1}}).forEach(function (obj) {
	var services = [];
	for (var i in obj.services) {
		var srvname = obj.services[i].name ? obj.services[i].name : obj.services[i];
		var dicKey = srvname + obj.sid;
		services.push({name: srvname, from: serviceDic[dicKey].from, to: serviceDic[dicKey].to});
	}
	obj.services = services;
	db.subscribers.save(obj);
});