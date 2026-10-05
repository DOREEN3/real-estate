import { useEffect, useRef, useState } from "react";
import DemoLogin from "./components/DemoLogin";
import Header from "./components/Header";
import PropertyEditor from "./components/PropertyEditor";
import Sidebar from "./components/SideBar";
import Dashboard from "./pages/Dashboard";
import Enquiries from "./pages/Enquiries";
import Notifications from "./pages/Notifications";
import Payments from "./pages/Payments";
import People from "./pages/People";
import Properties from "./pages/Properties";
import Rentals from "./pages/Rentals";
import Sales from "./pages/Sales";
import {
  initialAgents,
  initialEnquiries,
  initialNotifications,
  initialOwners,
  initialPayments,
  initialProperties,
  initialRentals,
  initialRentPayments,
  initialSales,
} from "./data/demoData";
import { demoUsers } from "./data/demoUsers";
import usePersistentState from "./hooks/usePersistentState";
import { getMatches } from "./lib/demo";

const rolePages = {
  admin: ["Dashboard", "Properties", "Enquiries", "Agents", "Owners", "Rentals", "Sales", "Payments", "Notifications"],
  agent: ["Dashboard", "Properties", "Enquiries", "Rentals", "Sales", "Notifications"],
  owner: ["Dashboard", "Properties", "Enquiries", "Rentals", "Notifications"],
};

function propertyRecipients(...properties) {
  const recipients = properties.flatMap((property) => [
    ...(property?.agentId ? [{ role: "agent", id: property.agentId }] : []),
    ...(property?.ownerId ? [{ role: "owner", id: property.ownerId }] : []),
  ]);
  return [...new Map(recipients.map((recipient) => [`${recipient.role}:${recipient.id}`, recipient])).values()];
}

function createNotification(title, message, audience, type, recipients = []) {
  return {
    id: Date.now() + Math.random(),
    title,
    message,
    audience,
    type,
    channels: ["Email", "SMS", "WhatsApp"],
    createdAt: new Date().toISOString(),
    recipients,
  };
}

function App() {
  const [activePage, setActivePage] = useState("Dashboard");
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [propertyEditorOpen, setPropertyEditorOpen] = useState(false);
  const [editingPropertyId, setEditingPropertyId] = useState(null);
  const [listingAttribution, setListingAttribution] = useState({});
  const [toast, setToast] = useState("");
  const toastTimer = useRef(null);

  const [properties, setProperties] = usePersistentState("golderp-demo-properties-v1", initialProperties);
  const [enquiries, setEnquiries] = usePersistentState("golderp-demo-enquiries-v1", initialEnquiries);
  const [agents, setAgents] = usePersistentState("golderp-demo-agents-v1", initialAgents);
  const [owners, setOwners] = usePersistentState("golderp-demo-owners-v1", initialOwners);
  const [sales, setSales] = usePersistentState("golderp-demo-sales-v1", initialSales);
  const [payments, setPayments] = usePersistentState("golderp-demo-payments-v1", initialPayments);
  const [rentals, setRentals] = usePersistentState("golderp-demo-rentals-v1", initialRentals);
  const [rentPayments, setRentPayments] = usePersistentState("golderp-demo-rent-payments-v1", initialRentPayments);
  const [notifications, setNotifications] = usePersistentState("golderp-demo-notifications-v1", initialNotifications);
  const [highlightedSaleId, setHighlightedSaleId] = useState(null);
  const [currentUser, setCurrentUser] = usePersistentState("golderp-demo-session-v1", null);

  const validUser = currentUser && demoUsers.some((account) =>
    account.email === currentUser.email && account.role === currentUser.role && account.id === currentUser.id
  );
  const user = validUser ? currentUser : null;
  const isAdmin = user?.role === "admin";
  const scopedProperties = !user || isAdmin
    ? properties
    : properties.filter((property) => user.role === "agent" ? property.agentId === user.id : property.ownerId === user.id);
  const scopedPropertyIds = new Set(scopedProperties.map((property) => property.id));
  const scopedEnquiries = !user || isAdmin
    ? enquiries
    : enquiries.filter((enquiry) =>
      (user.role === "agent" && enquiry.agentId === user.id) ||
      scopedPropertyIds.has(enquiry.propertyId) ||
      scopedProperties.some((property) => getMatches(property, [enquiry]).length > 0)
    );
  const scopedSales = !user || isAdmin ? sales : sales.filter((sale) => scopedPropertyIds.has(sale.propertyId));
  const scopedSaleIds = new Set(scopedSales.map((sale) => sale.id));
  const scopedPayments = !user || isAdmin ? payments : payments.filter((payment) => scopedSaleIds.has(payment.saleId));
  const scopedRentals = !user || isAdmin ? rentals : rentals.filter((rental) => scopedPropertyIds.has(rental.propertyId));
  const scopedRentalIds = new Set(scopedRentals.map((rental) => rental.id));
  const scopedRentPayments = !user || isAdmin ? rentPayments : rentPayments.filter((payment) => scopedRentalIds.has(payment.rentalId));
  const scopedAgents = !user || isAdmin ? agents : user.role === "agent" ? agents.filter((agent) => agent.id === user.id) : [];
  const scopedOwners = !user || isAdmin ? owners : user.role === "owner" ? owners.filter((owner) => owner.id === user.id) : [];
  const scopedNotifications = !user
    ? []
    : isAdmin
    ? notifications
    : notifications.filter((notification) => notification.recipients?.some((recipient) =>
      recipient.role === user.role && recipient.id === user.id
    ));
  const allowedPages = user ? rolePages[user.role] : [];

  useEffect(() => () => window.clearTimeout(toastTimer.current), []);

  useEffect(() => {
    const rentedPropertyIds = new Set(rentals.filter((rental) => rental.status === "Active").map((rental) => rental.propertyId));
    setProperties((previous) => {
      let changed = false;
      const next = previous.map((property) => {
        if (!rentedPropertyIds.has(property.id) || property.status === "Rented") return property;
        changed = true;
        return { ...property, status: "Rented" };
      });
      return changed ? next : previous;
    });
  }, [rentals, setProperties]);

  useEffect(() => {
    const ownerProperty = initialProperties.find((property) => property.id === 7);
    const ownerRental = initialRentals.find((rental) => rental.id === 1002);
    const ownerRentPayment = initialRentPayments.find((payment) => payment.id === 1102);
    if (ownerProperty) {
      setProperties((previous) => previous.some((property) => property.id === ownerProperty.id) ? previous : [...previous, ownerProperty]);
    }
    if (ownerRental) {
      setRentals((previous) => previous.some((rental) => rental.id === ownerRental.id) ? previous : [...previous, ownerRental]);
    }
    if (ownerRentPayment) {
      setRentPayments((previous) => previous.some((payment) => payment.id === ownerRentPayment.id) ? previous : [ownerRentPayment, ...previous]);
    }
    setNotifications((previous) => {
      const existingIds = new Set(previous.map((notification) => notification.id));
      const missingNotifications = initialNotifications.filter((notification) => !existingIds.has(notification.id));
      return missingNotifications.length ? [...missingNotifications, ...previous] : previous;
    });
  }, [setProperties, setRentals, setRentPayments, setNotifications]);

  function notifyUser(message) {
    window.clearTimeout(toastTimer.current);
    setToast(message);
    toastTimer.current = window.setTimeout(() => setToast(""), 6000);
  }

  function addActivity(activity) {
    setNotifications((previous) => [activity, ...previous].slice(0, 100));
  }

  function handleAddProperty(form) {
    const roleAttribution = user?.role === "agent"
      ? { listedBy: "Verified agent", agentId: user.id, ownerId: null }
      : user?.role === "owner"
        ? { listedBy: "Verified owner", agentId: null, ownerId: user.id }
        : {};
    const newProperty = {
      ...form,
      id: Date.now(),
      price: Number(form.price),
      bedrooms: Number(form.bedrooms) || 0,
      bathrooms: Number(form.bathrooms) || 0,
      location: {
        country: form.country.trim(),
        city: form.city.trim(),
        area: form.area.trim(),
        latitude: form.latitude === "" ? null : Number(form.latitude),
        longitude: form.longitude === "" ? null : Number(form.longitude),
      },
      agentId: roleAttribution.agentId ?? (form.agentId ? Number(form.agentId) : null),
      ownerId: roleAttribution.ownerId ?? (form.ownerId ? Number(form.ownerId) : null),
      listedBy: roleAttribution.listedBy || form.listedBy,
      features: [],
    };
    setProperties((previous) => [newProperty, ...previous]);
    if (newProperty.agentId) setAgents((previous) => previous.map((agent) => agent.id === newProperty.agentId ? { ...agent, listings: (agent.listings || 0) + 1 } : agent));
    if (newProperty.ownerId) setOwners((previous) => previous.map((owner) => owner.id === newProperty.ownerId ? { ...owner, properties: (owner.properties || 0) + 1 } : owner));

    addActivity(createNotification(
      "New property shared with agents",
      `${newProperty.title} in ${newProperty.location.city} was added. Agent notifications are ready for review.`,
      `${agents.length} registered agents · admin team`,
      "Property",
      [
        ...agents.filter((agent) => agent.verified).map((agent) => ({ role: "agent", id: agent.id })),
        ...propertyRecipients(newProperty),
      ],
    ));
    const matchedEnquiries = enquiries.filter((enquiry) => getMatches(newProperty, [enquiry]).length > 0);
    if (matchedEnquiries.length) {
      const recipientNames = matchedEnquiries.map((enquiry) => enquiry.name).join(", ");
      addActivity(createNotification(
        "New property matches buyer requirements",
        `${newProperty.title} matches ${recipientNames}. Notifications include the matched leads, listing agent and owner where assigned, plus the admin team.`,
        `${matchedEnquiries.length} lead${matchedEnquiries.length === 1 ? "" : "s"} · listing contacts · admin team`,
        "Match",
        propertyRecipients(newProperty),
      ));
    }
    notifyUser(`Property saved. Simulated email, SMS and WhatsApp alerts queued for ${agents.length} registered agents.`);
    console.log("Property saved:", newProperty);
    setActivePage("Properties");
    setPropertyEditorOpen(false);
    setListingAttribution({});
    setEditingPropertyId(null);
  }

  function beginEditProperty(property) {
    setEditingPropertyId(property.id);
    setListingAttribution({
      ...property,
      country: property.location.country || "",
      city: property.location.city || "",
      area: property.location.area || "",
      latitude: property.location.latitude ?? "",
      longitude: property.location.longitude ?? "",
      agentId: property.agentId ? String(property.agentId) : "",
      ownerId: property.ownerId ? String(property.ownerId) : "",
    });
    setPropertyEditorOpen(true);
  }

  function handleUpdateProperty(form) {
    const property = properties.find((item) => item.id === editingPropertyId);
    const canEdit = property && (isAdmin ||
      (user.role === "agent" && property.agentId === user.id) ||
      (user.role === "owner" && property.ownerId === user.id));
    if (!canEdit) {
      notifyUser("Property could not be updated because it is not linked to your account.");
      return;
    }
    const hasActiveRental = rentals.some((rental) => rental.propertyId === property.id && rental.status === "Active");
    const updatedProperty = {
      ...property,
      ...form,
      id: property.id,
      listingType: hasActiveRental ? "For Rent" : form.listingType,
      status: hasActiveRental ? "Rented" : form.status,
      price: Number(form.price),
      bedrooms: Number(form.bedrooms) || 0,
      bathrooms: Number(form.bathrooms) || 0,
      location: {
        country: form.country.trim(),
        city: form.city.trim(),
        area: form.area.trim(),
        latitude: form.latitude === "" ? null : Number(form.latitude),
        longitude: form.longitude === "" ? null : Number(form.longitude),
      },
      agentId: property.agentId,
      ownerId: property.ownerId,
    };
    setProperties((previous) => previous.map((item) => item.id === property.id ? updatedProperty : item));
    setPropertyEditorOpen(false);
    setEditingPropertyId(null);
    setListingAttribution({});
    notifyUser("Property updated.");
  }

  function handleAddEnquiry(form, property = null) {
    if (user.role === "owner") {
      notifyUser("Owners can view related enquiries but cannot add enquiries.");
      return;
    }
    const newEnquiry = {
      ...form,
      source: form.source || "Website",
      agentId: property?.agentId || (user?.role === "agent" ? user.id : null),
      id: Date.now(),
      budgetMin: Number(form.budgetMin) || 0,
      budgetMax: Number(form.budgetMax) || 0,
      propertyId: property?.id || null,
      propertyTitle: property?.title || "",
      status: "New",
      createdAt: new Date().toISOString(),
    };
    const matches = properties.filter((listedProperty) => getMatches(listedProperty, [newEnquiry]).length > 0);
    setEnquiries((previous) => [newEnquiry, ...previous]);
    addActivity(createNotification(
      "New buyer enquiry received",
      `${newEnquiry.name} is looking for ${newEnquiry.propertyType || "a property"}${newEnquiry.city ? ` in ${newEnquiry.city}` : ""}. Follow-up is assigned to the admin team.`,
      "Admin team · lead",
      "Enquiry",
      propertyRecipients(property, ...matches),
    ));
    if (matches.length) {
      addActivity(createNotification(
        "Buyer enquiry matched to listings",
        `${newEnquiry.name} matches ${matches.map((match) => match.title).join(", ")}. Alert the lead, listing contact and admin team.`,
        `${newEnquiry.name} · listing contacts · admin team`,
        "Match",
        propertyRecipients(...matches),
      ));
    }
    notifyUser(matches.length
      ? `Enquiry saved and matched with ${matches.length} ${matches.length === 1 ? "property" : "properties"}.`
      : "Enquiry saved. We will check new listings against this buyer's requirements.");
    console.log("Enquiry submitted:\n", JSON.stringify(newEnquiry, null, 2));
  }

  function handleUpdateEnquiryStatus(id, status) {
    if (user.role === "owner" || !scopedEnquiries.some((enquiry) => enquiry.id === id)) {
      notifyUser("Enquiry status could not be updated because it is read-only or outside your account.");
      return;
    }
    setEnquiries((previous) => previous.map((enquiry) => enquiry.id === id ? { ...enquiry, status } : enquiry));
    notifyUser(`Enquiry status updated to ${status}.`);
  }

  function handleNotifyMatch(enquiry, matchedProperties) {
    if (user.role === "owner" || !scopedEnquiries.some((item) => item.id === enquiry.id) ||
      matchedProperties.some((property) => !scopedPropertyIds.has(property.id))) {
      notifyUser("Match alert could not be prepared because the enquiry or property is outside your account.");
      return;
    }
    const agentIds = new Set(matchedProperties.map((property) => property.agentId).filter(Boolean));
    const ownerIds = new Set(matchedProperties.map((property) => property.ownerId).filter(Boolean));
    const agentNames = agents.filter((agent) => agentIds.has(agent.id)).map((agent) => agent.name);
    const ownerNames = owners.filter((owner) => ownerIds.has(owner.id)).map((owner) => owner.name);
    const audience = [enquiry.name, ...agentNames, ...ownerNames, "GoldERP admin team"].join(" · ");
    addActivity(createNotification(
      "Serious property availability alert",
      `${matchedProperties.map((property) => property.title).join(", ")} match ${enquiry.name}'s requirements. This demo records alerts for the lead, listing contact${ownerNames.length ? ", owner" : ""} and admin team.`,
      audience,
      "Match",
      propertyRecipients(...matchedProperties),
    ));
    handleUpdateEnquiryStatus(enquiry.id, "Matched");
    notifyUser(`Availability alert recorded for ${audience}. No external messages were sent.`);
  }

  function handleAddPerson(kind, person) {
    if (!isAdmin) {
      notifyUser("Only admins can register agents or owners.");
      return;
    }
    const newPerson = { ...person, id: Date.now(), verified: false, listings: 0, properties: 0 };
    const updateList = kind === "Agents" ? setAgents : setOwners;
    updateList((previous) => [newPerson, ...previous]);
    addActivity(createNotification(
      `${kind.slice(0, -1)} registration received`,
      `${person.name} registered and is awaiting identity verification.`,
      "Admin team",
      "Enquiry",
    ));
    notifyUser(`${kind.slice(0, -1)} registration saved and marked pending verification.`);
  }

  function handleUpdateVerification(kind, id, verified) {
    if (!isAdmin) {
      notifyUser("Only admins can update agent or owner verification.");
      return;
    }
    const updateList = kind === "Agents" ? setAgents : setOwners;
    updateList((previous) => previous.map((person) => person.id === id ? { ...person, verified } : person));
    notifyUser(`${kind.slice(0, -1)} ${verified ? "verified" : "verification revoked"}.`);
  }

  function handleListForPerson(kind, person) {
    setListingAttribution(kind === "Agents"
      ? { listedBy: "Verified agent", agentId: String(person.id), country: person.country || "Kenya", city: person.city || "" }
      : { listedBy: "Verified owner", ownerId: String(person.id), country: person.country || "Kenya", city: person.city || "" });
    setPropertyEditorOpen(true);
  }

  function handleAddSale(form) {
    const property = properties.find((item) => item.id === form.propertyId);
    const canRegister = isAdmin || (user.role === "agent" && property?.agentId === user.id);
    if (!canRegister || !property || property.listingType !== "For Sale" || property.status !== "Available") {
      notifyUser("Sale could not be saved because the selected property is unavailable.");
      return;
    }
    const saleId = Date.now();
    const salePrice = Number(form.salePrice);
    const deposit = Number(form.deposit);
    const completed = deposit >= salePrice;
    const sale = {
      id: saleId,
      propertyId: property.id,
      propertyTitle: property.title,
      buyerName: form.buyerName.trim(),
      buyerEmail: form.buyerEmail.trim(),
      currency: property.currency || "KSH",
      salePrice,
      deposit,
      installmentAmount: Number(form.installmentAmount) || 0,
      nextDueDate: form.nextDueDate,
      installmentsTotal: Number(form.installmentsTotal) || 0,
      installmentsPaid: 0,
      status: completed ? "Completed" : "In progress",
      createdAt: new Date().toISOString(),
    };
    const receiptId = Date.now();
    const depositReceipt = {
      id: receiptId,
      saleId,
      propertyTitle: property.title,
      buyerName: sale.buyerName,
      currency: property.currency || "KSH",
      amount: deposit,
      method: "Deposit",
      reference: `RCP-${new Date().getFullYear()}-${String(receiptId).slice(-6)}`,
      date: new Date().toISOString().slice(0, 10),
      note: "Initial sale deposit",
    };
    setSales((previous) => [sale, ...previous]);
    setPayments((previous) => [depositReceipt, ...previous]);
    setProperties((previous) => previous.map((item) => item.id === property.id ? { ...item, status: "Sold" } : item));
    addActivity(createNotification(
      "Sale agreement registered",
      `${sale.propertyTitle} was sold to ${sale.buyerName}. Deposit receipt ${depositReceipt.reference} issued.`,
      `${sale.buyerName} · admin team`,
      "Payment",
      propertyRecipients(property),
    ));
    notifyUser(`Sale saved. Deposit receipt ${depositReceipt.reference} issued.`);
  }

  function handleRecordPayment(form) {
    if (!isAdmin) {
      notifyUser("Only admins can record sale payments.");
      return;
    }
    const sale = sales.find((item) => item.id === form.saleId);
    if (!sale) {
      notifyUser("Payment could not be recorded because the sale account is unavailable.");
      return;
    }
    const paymentId = Date.now();
    const payment = {
      id: paymentId,
      saleId: sale.id,
      propertyTitle: sale.propertyTitle,
      buyerName: sale.buyerName,
      currency: sale.currency || "KSH",
      amount: Number(form.amount),
      method: form.method,
      reference: `RCP-${new Date().getFullYear()}-${String(paymentId).slice(-6)}`,
      date: form.date,
      note: form.note.trim(),
    };
    const priorPayments = payments.filter((item) => item.saleId === sale.id).reduce((sum, item) => sum + Number(item.amount), 0);
    const fullyPaid = priorPayments + payment.amount >= sale.salePrice;
    const nextDue = new Date(`${form.date}T12:00:00`);
    nextDue.setMonth(nextDue.getMonth() + 1);
    setPayments((previous) => [payment, ...previous]);
    setSales((previous) => previous.map((item) => item.id === sale.id ? {
      ...item,
      status: fullyPaid ? "Completed" : "In progress",
      installmentsPaid: item.installmentsPaid + (fullyPaid ? 0 : 1),
      nextDueDate: fullyPaid ? "" : nextDue.toISOString().slice(0, 10),
    } : item));
    addActivity(createNotification(
      "Sale payment receipted",
      `${formatCurrencyForActivity(payment.amount, sale.currency)} received from ${sale.buyerName}. Receipt ${payment.reference} issued.`,
      `${sale.buyerName} · admin team`,
      "Payment",
      propertyRecipients(properties.find((property) => property.id === sale.propertyId)),
    ));
    notifyUser(`Payment saved. Receipt ${payment.reference} issued.`);
  }

  function handleAddRental(form) {
    const property = properties.find((item) => item.id === form.propertyId);
    const canRegister = isAdmin || (user.role === "agent" && property?.agentId === user.id);
    if (!canRegister || !property || property.listingType !== "For Rent" || property.status !== "Available" || rentals.some((rental) => rental.propertyId === form.propertyId && rental.status === "Active")) {
      notifyUser("Rental could not be saved because the selected property is unavailable.");
      return;
    }
    const rental = {
      id: Date.now(),
      propertyId: property.id,
      propertyTitle: property.title,
      tenantName: form.tenantName.trim(),
      tenantEmail: form.tenantEmail.trim(),
      currency: property.currency || "KSH",
      monthlyRent: Number(form.monthlyRent),
      dueDay: Number(form.dueDay),
      status: "Active",
      createdAt: new Date().toISOString(),
    };
    setRentals((previous) => [rental, ...previous]);
    setProperties((previous) => previous.map((item) => item.id === property.id ? { ...item, status: "Rented" } : item));
    addActivity(createNotification(
      "Rental agreement registered",
      `${rental.propertyTitle} was rented to ${rental.tenantName} at ${formatCurrencyForActivity(rental.monthlyRent, rental.currency)} per month.`,
      `${rental.tenantName} · admin team`,
      "Rental",
      propertyRecipients(property),
    ));
    notifyUser(`Rental saved for ${rental.tenantName}. Monthly rent tracking is ready.`);
  }

  function handleRecordRentPayment(form) {
    if (!isAdmin) {
      notifyUser("Only admins can record rent payments.");
      return;
    }
    const rental = rentals.find((item) => item.id === form.rentalId && item.status === "Active");
    const amount = Number(form.amount);
    if (!rental || !Number.isFinite(amount) || amount <= 0 || !/^\d{4}-\d{2}$/.test(form.period)) {
      notifyUser("Rent payment could not be recorded. Check the rental account, month and amount.");
      return;
    }
    const paymentId = Date.now();
    const payment = {
      id: paymentId,
      rentalId: rental.id,
      propertyTitle: rental.propertyTitle,
      tenantName: rental.tenantName,
      currency: rental.currency || "KSH",
      amount,
      period: form.period,
      method: form.method,
      reference: `RNT-${new Date().getFullYear()}-${String(paymentId).slice(-6)}`,
      date: form.date,
      note: form.note.trim(),
    };
    setRentPayments((previous) => [payment, ...previous]);
    addActivity(createNotification(
      "Rent payment receipted",
      `${formatCurrencyForActivity(payment.amount, rental.currency)} rent received from ${rental.tenantName} for ${payment.period}. Receipt ${payment.reference} issued.`,
      `${rental.tenantName} · admin team`,
      "Payment",
      propertyRecipients(properties.find((property) => property.id === rental.propertyId)),
    ));
    notifyUser(`Rent payment saved. Receipt ${payment.reference} issued.`);
  }

  function handleSendReminder(sale) {
    if (user.role === "owner" || !scopedSales.some((item) => item.id === sale.id)) {
      notifyUser("Reminder could not be prepared because the sale is outside your account.");
      return;
    }
    addActivity(createNotification(
      "Installment reminder prepared",
      `${sale.buyerName} has a ${formatCurrencyForActivity(sale.installmentAmount, sale.currency)} installment for ${sale.propertyTitle}${sale.nextDueDate ? ` due ${sale.nextDueDate}` : ""}.`,
      `${sale.buyerName} · admin team`,
      "Payment",
      propertyRecipients(properties.find((property) => property.id === sale.propertyId)),
    ));
    notifyUser(`Installment reminder prepared for ${sale.buyerName}. External messages are simulated.`);
  }

  function navigate(page) {
    if (!allowedPages.includes(page)) return;
    setActivePage(page);
    setSidebarOpen(false);
    if (page !== "Payments") setHighlightedSaleId(null);
  }

  function beginAddProperty() {
    const initialValues = user?.role === "agent"
      ? { listedBy: "Verified agent", agentId: String(user.id) }
      : user?.role === "owner"
        ? { listedBy: "Verified owner", ownerId: String(user.id) }
        : {};
    setListingAttribution(initialValues);
    setEditingPropertyId(null);
    setPropertyEditorOpen(true);
  }

  function handleLogin(nextUser) {
    setCurrentUser(nextUser);
    setActivePage("Dashboard");
  }

  function handleLogout() {
    setCurrentUser(null);
    setActivePage("Dashboard");
    setPropertyEditorOpen(false);
    setListingAttribution({});
  }

  function renderPage() {
    if (activePage === "Dashboard") return <Dashboard properties={scopedProperties} enquiries={scopedEnquiries} agents={scopedAgents} sales={scopedSales} payments={isAdmin ? scopedPayments : []} rentals={scopedRentals} rentPayments={scopedRentPayments} notifications={scopedNotifications} onNavigate={navigate} onAddProperty={beginAddProperty} user={user} />;
    if (activePage === "Properties") return <Properties properties={scopedProperties} enquiries={scopedEnquiries} onRequestAdd={beginAddProperty} onEditProperty={beginEditProperty} onSaveEnquiry={handleAddEnquiry} />;
    if (activePage === "Enquiries") return <Enquiries enquiries={scopedEnquiries} properties={scopedProperties} onAddEnquiry={handleAddEnquiry} onUpdateStatus={handleUpdateEnquiryStatus} onNotifyMatch={handleNotifyMatch} readOnly={user.role === "owner"} />;
    if (activePage === "Agents" && isAdmin) return <People kind="Agents" people={agents} onAddPerson={handleAddPerson} onListProperty={handleListForPerson} onUpdateVerification={(id, verified) => handleUpdateVerification("Agents", id, verified)} />;
    if (activePage === "Owners" && isAdmin) return <People kind="Owners" people={owners} onAddPerson={handleAddPerson} onListProperty={handleListForPerson} onUpdateVerification={(id, verified) => handleUpdateVerification("Owners", id, verified)} />;
    if (activePage === "Rentals") return <Rentals rentals={scopedRentals} properties={scopedProperties} rentPayments={scopedRentPayments} onAddRental={handleAddRental} onRecordRentPayment={handleRecordRentPayment} canRecordPayment={isAdmin} canViewReceipts={isAdmin || user.role === "owner"} canRegisterRental={user.role !== "owner"} readOnly={user.role === "owner"} />;
    if (activePage === "Sales" && user.role !== "owner") return <Sales sales={scopedSales} properties={scopedProperties} enquiries={scopedEnquiries} payments={isAdmin ? scopedPayments : []} onAddSale={handleAddSale} onSendReminder={handleSendReminder} onGoPayments={(saleId) => { setHighlightedSaleId(saleId); navigate("Payments"); }} canRecordPayment={isAdmin} canViewPaymentHistory={isAdmin} />;
    if (activePage === "Payments" && isAdmin) return <Payments payments={payments} sales={sales} highlightedSaleId={highlightedSaleId} onRecordPayment={handleRecordPayment} />;
    if (activePage === "Notifications") return <Notifications notifications={scopedNotifications} user={user} />;
    return <Dashboard properties={scopedProperties} enquiries={scopedEnquiries} agents={scopedAgents} sales={scopedSales} payments={isAdmin ? scopedPayments : []} rentals={scopedRentals} rentPayments={scopedRentPayments} notifications={scopedNotifications} onNavigate={navigate} onAddProperty={beginAddProperty} user={user} />;
  }

  if (!user) return <DemoLogin onLogin={handleLogin} />;

  return (
    <div className="min-h-screen bg-[#f6f7f9] text-slate-800">
      <Sidebar activePage={activePage} setActivePage={navigate} isOpen={sidebarOpen} onClose={() => setSidebarOpen(false)} role={user.role} />
      <div className="min-h-screen min-w-0 md:ml-64">
        <Header onToggleSidebar={() => setSidebarOpen((previous) => !previous)} onOpenNotifications={() => navigate("Notifications")} notificationCount={scopedNotifications.length} user={user} onLogout={handleLogout} />
        <main className="px-4 py-6 sm:px-6 lg:px-8">
          <div className="mx-auto max-w-360">{renderPage()}</div>
        </main>
      </div>
      {propertyEditorOpen && <PropertyEditor initialValues={listingAttribution} agents={scopedAgents} owners={scopedOwners} isEditing={editingPropertyId !== null} onClose={() => { setPropertyEditorOpen(false); setEditingPropertyId(null); setListingAttribution({}); }} onSave={editingPropertyId ? handleUpdateProperty : handleAddProperty} />}
      {toast && <div role="status" aria-live="polite" className="fixed bottom-4 left-4 right-4 z-70 mx-auto flex max-w-xl items-start gap-3 rounded-2xl bg-[#0F2A43] px-5 py-4 text-sm font-medium text-white shadow-2xl sm:bottom-6 sm:left-auto sm:right-6"><span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-emerald-400" /><span>{toast}</span></div>}
    </div>
  );
}

function formatCurrencyForActivity(value, currency = "KSH") {
  return `${currency} ${Number(value || 0).toLocaleString()}`;
}

export default App;
