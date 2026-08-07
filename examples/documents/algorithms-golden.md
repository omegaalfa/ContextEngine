# Algoritmos de referência para avaliação

## Dijkstra

O algoritmo de Dijkstra encontra o menor caminho de uma origem até os demais vértices em um grafo com pesos não negativos. Ele mantém distâncias provisórias, seleciona repetidamente o vértice de menor distância em uma fila de prioridade (min-heap) e relaxa suas arestas. Com lista de adjacência e heap binário, sua complexidade é O((V + E) log V).

## Bellman-Ford

Bellman-Ford calcula menores caminhos a partir de uma origem mesmo quando existem arestas com pesos negativos. O algoritmo relaxa todas as arestas V - 1 vezes e faz uma passagem adicional para detectar ciclos de peso negativo. Sua complexidade de tempo é O(VE), também escrita O(V * E) ou O(|V||E|).

## Quicksort

Quicksort escolhe um pivô, particiona o vetor entre elementos menores e maiores e ordena recursivamente as duas partes. Sua complexidade média é O(n log n), mas escolhas ruins de pivô podem produzir O(n²) no pior caso. O particionamento pode ser feito in-place.

## Floyd-Warshall

Floyd-Warshall encontra os menores caminhos entre todos os pares de vértices. A programação dinâmica testa cada vértice como intermediário e atualiza uma matriz de distâncias. Aceita arestas negativas, mas não ciclos negativos, e executa em O(V³), usando O(V²) de espaço.

## Merge Sort

Merge Sort divide o vetor em metades, ordena cada metade recursivamente e realiza a intercalação dos resultados ordenados. Sua complexidade de tempo é O(n log n) em todos os casos. A implementação tradicional usa O(n) de memória auxiliar e é estável.

## Heap Sort

Heap Sort transforma o vetor em um max-heap e remove repetidamente o maior elemento para o final do vetor. A construção do heap custa O(n) e a ordenação completa custa O(n log n). O algoritmo é in-place, mas normalmente não é estável.

## Árvore AVL

Uma árvore AVL é uma árvore de busca binária balanceada. Para cada nó, a diferença entre as alturas das subárvores esquerda e direita, chamada fator de balanceamento, deve ser -1, 0 ou 1. Rotações simples ou duplas restauram o equilíbrio, mantendo busca, inserção e remoção em O(log n).

