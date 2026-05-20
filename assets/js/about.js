        lucide.createIcons();

        // Intersection Observer to update active dot
        document.addEventListener('DOMContentLoaded', () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        // Access Alpine data to update state
                        const element = document.querySelector('[x-data]');
                        if(element) element.__x.$data.activeSection = entry.target.id;

                        // Re-trigger animations by removing and adding class
                        const animatables = entry.target.querySelectorAll('.fade-in-up');
                        animatables.forEach(el => {
                            el.style.animation = 'none';
                            el.offsetHeight; /* trigger reflow */
                            el.style.animation = null;
                        });
                    }
                });
            }, { threshold: 0.5 });

            document.querySelectorAll('section').forEach(section => {
                observer.observe(section);
            });
        });
